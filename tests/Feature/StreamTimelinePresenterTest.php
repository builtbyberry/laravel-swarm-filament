<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarmFilament\Support\StreamTimelinePresenter;

/*
 * StreamTimelinePresenter — the pure causal-log fold (audit F6). Exercised through
 * the array-in / array-out core `build()`, below the typed-event `present()` adapter.
 * Covers the void-edge fold (present vs ABSENT target → the missing-predecessor
 * danger marker), per-node bucketing (multi-node + top-level), last-edge-wins
 * supersession, representative `summarize()` arms, and the ciphertext scrub.
 */

// --- Void-edge fold: present target ---------------------------------------

test('a void-edge whose target is present sits in the target node and marks it voided (warning)', function () {
    $timeline = StreamTimelinePresenter::build([
        ['id' => 'e1', 'type' => 'swarm_step_start', 'node_id' => 'node-aaaaaaaa-1111', 'step_index' => 0, 'agent_class' => 'App\\Agents\\Writer', 'timestamp' => 100],
        ['id' => 'v1', 'type' => 'swarm_causal_void_edge', 'target_event_id' => 'e1', 'void_type' => 'supersedes', 'reason' => 'newer plan', 'node_id' => 'node-aaaaaaaa-1111', 'timestamp' => 150],
    ]);

    expect($timeline['event_count'])->toBe(1)
        ->and($timeline['void_edge_count'])->toBe(1)
        ->and($timeline['node_count'])->toBe(1);

    $node = $timeline['nodes'][0];
    expect($node['node_id'])->toBe('node-aaaaaaaa-1111')
        ->and($node['is_top_level'])->toBeFalse()
        ->and($node['label'])->toBe('Node node-aaa…')
        ->and($node['event_count'])->toBe(1) // the void-edge row is not counted as an event
        ->and($node['events'])->toHaveCount(2);

    // The targeted event is annotated as voided (warning), never dropped.
    $event = $node['events'][0];
    expect($event['event_id'])->toBe('e1')
        ->and($event['marker'])->toBe('Voided · superseded — newer plan')
        ->and($event['marker_color'])->toBe('warning')
        ->and($event['summary'])->toBe('Step 0 started · App\\Agents\\Writer');

    // The void-edge is a first-class marker row next to its target.
    $marker = $node['events'][1];
    expect($marker['type'])->toBe('swarm_causal_void_edge')
        ->and($marker['label'])->toBe('Void edge')
        ->and($marker['summary'])->toBe('Voids e1')
        ->and($marker['marker'])->toBe('superseded — newer plan')
        ->and($marker['marker_color'])->toBe('warning');
});

// --- Void-edge fold: ABSENT target (missing/nulled predecessor) -----------

test('a void-edge whose target is absent from the window renders the missing-predecessor danger marker', function () {
    $timeline = StreamTimelinePresenter::build([
        ['id' => 'v2', 'type' => 'swarm_causal_void_edge', 'target_event_id' => 'ghost', 'void_type' => 'node_reexecuted', 'reason' => '', 'node_id' => 'node-b', 'timestamp' => 200],
    ]);

    expect($timeline['event_count'])->toBe(0)
        ->and($timeline['void_edge_count'])->toBe(1)
        ->and($timeline['node_count'])->toBe(1);

    // No present target → the edge falls to its own node, not the target's.
    $node = $timeline['nodes'][0];
    expect($node['node_id'])->toBe('node-b')
        ->and($node['label'])->toBe('Node node-b');

    $marker = $node['events'][0];
    expect($marker['summary'])->toBe('Voids missing predecessor ghost')
        ->and($marker['marker'])->toBe('re-executed · predecessor missing from window')
        ->and($marker['marker_color'])->toBe('danger'); // distinct from a present-target void (warning)
});

// --- Node bucketing: multi-node + top-level -------------------------------

test('events bucket by node_id in first-seen order, with a synthetic top-level group for node-less events', function () {
    $timeline = StreamTimelinePresenter::build([
        ['id' => 'a', 'type' => 'swarm_stream_start', 'node_id' => 'na', 'swarm_class' => 'App\\Swarms\\Support', 'topology' => 'hierarchical', 'timestamp' => 1],
        ['id' => 'b', 'type' => 'swarm_node_opened', 'node_id' => 'nb', 'role' => 'worker', 'timestamp' => 2],
        ['id' => 'c', 'type' => 'swarm_stream_end', 'timestamp' => 3], // no node_id → top-level
    ]);

    expect($timeline['node_count'])->toBe(3)
        ->and($timeline['event_count'])->toBe(3)
        ->and(array_column($timeline['nodes'], 'node_id'))->toBe(['na', 'nb', null]);

    $topLevel = $timeline['nodes'][2];
    expect($topLevel['is_top_level'])->toBeTrue()
        ->and($topLevel['node_id'])->toBeNull()
        ->and($topLevel['label'])->toBe('Top-level events');

    // Representative summarize() arms surfaced through bucketing.
    expect($timeline['nodes'][0]['events'][0]['summary'])->toBe('Stream started · App\\Swarms\\Support (hierarchical)')
        ->and($timeline['nodes'][1]['events'][0]['summary'])->toBe('Node opened (worker)')
        ->and($topLevel['events'][0]['summary'])->toBe('Stream ended');
});

// --- Last-edge-wins on a superseded event ---------------------------------

test('when several void-edges target one event the most recent (last, causal order) wins', function () {
    $timeline = StreamTimelinePresenter::build([
        ['id' => 'e2', 'type' => 'swarm_text_end', 'node_id' => 'n', 'timestamp' => 10],
        ['id' => 'v3', 'type' => 'swarm_causal_void_edge', 'target_event_id' => 'e2', 'void_type' => 'supersedes', 'reason' => 'first', 'timestamp' => 11],
        ['id' => 'v4', 'type' => 'swarm_causal_void_edge', 'target_event_id' => 'e2', 'void_type' => 'replaces', 'reason' => 'second', 'timestamp' => 12],
    ]);

    // The event's annotation reflects the LAST edge (replaces), not the first.
    expect($timeline['nodes'][0]['events'][0]['marker'])->toBe('Voided · replaced — second')
        ->and($timeline['void_edge_count'])->toBe(2);
});

// --- summarize(): representative arms of the 14 -----------------------------

test('summarize renders structural, ciphertext-safe one-liners across event types', function () {
    $timeline = StreamTimelinePresenter::build([
        ['id' => 's1', 'type' => 'swarm_step_end', 'node_id' => 'n', 'step_index' => 1, 'agent_class' => 'X', 'duration_ms' => 250, 'timestamp' => 1],
        ['id' => 's2', 'type' => 'swarm_text_delta', 'node_id' => 'n', 'delta' => 'hello', 'timestamp' => 2],
        ['id' => 's3', 'type' => 'swarm_tool_call', 'node_id' => 'n', 'tool_call' => ['name' => 'search'], 'timestamp' => 3],
        ['id' => 's4', 'type' => 'swarm_node_children_decided', 'node_id' => 'n', 'child_node_ids' => ['x', 'y', 'z'], 'timestamp' => 4],
        ['id' => 's5', 'type' => 'custom_thing', 'node_id' => 'n', 'timestamp' => 5], // unknown → degrades to the raw type
    ]);

    $summaries = array_column($timeline['nodes'][0]['events'], 'summary');
    expect($summaries)->toBe([
        'Step 1 ended · X (250ms)',
        'Text delta (5 chars)',
        'Tool call (search)',
        'Children decided (3)',
        'custom_thing',
    ]);
});

// --- Ciphertext defense in depth ------------------------------------------

test('a payload leaf that still looks like sw0: ciphertext is masked in the rendered payload', function () {
    $timeline = StreamTimelinePresenter::build([
        ['id' => 'p', 'type' => 'swarm_tool_result', 'node_id' => 'n', 'tool_call' => ['name' => 'x'], 'result' => 'sw0:secret-ciphertext', 'timestamp' => 5],
    ]);

    $payload = $timeline['nodes'][0]['events'][0]['payload'];
    expect($payload)->toContain('unavailable')
        ->and($payload)->not->toContain('sw0:secret-ciphertext');
});

// --- Tolerance / empties --------------------------------------------------

test('build tolerates non-array entries and an empty log', function () {
    expect(StreamTimelinePresenter::build([]))->toBe([
        'event_count' => 0,
        'void_edge_count' => 0,
        'node_count' => 0,
        'nodes' => [],
    ]);

    // A stray non-array payload is skipped, not fatal.
    $timeline = StreamTimelinePresenter::build([null, ['id' => 'e', 'type' => 'swarm_stream_end', 'timestamp' => 1]]);
    expect($timeline['event_count'])->toBe(1)
        ->and($timeline['node_count'])->toBe(1);
});

// --- present(): the typed-event adapter -----------------------------------

test('present adapts a typed event stream by folding each event toArray through build', function () {
    $event = new class
    {
        /** @return array<string, mixed> */
        public function toArray(): array
        {
            return ['id' => 'x', 'type' => 'swarm_stream_start', 'swarm_class' => 'App\\Swarms\\Demo', 'timestamp' => 1];
        }
    };

    $timeline = StreamTimelinePresenter::present([$event]);

    expect($timeline['event_count'])->toBe(1)
        ->and($timeline['nodes'][0]['events'][0]['summary'])->toBe('Stream started · App\\Swarms\\Demo');
});
