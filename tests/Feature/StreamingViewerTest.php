<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Contracts\CausalLogStore;
use BuiltByBerry\LaravelSwarm\Contracts\StreamEventStore;
use BuiltByBerry\LaravelSwarm\Streaming\Events\CausalVoidEdgeType;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmStepStart;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmStreamEvent;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmStreamStart;
use BuiltByBerry\LaravelSwarmFilament\Pages\ViewSwarmStream;
use BuiltByBerry\LaravelSwarmFilament\Support\StreamTimelinePresenter;
use BuiltByBerry\LaravelSwarmFilament\SwarmFilamentPlugin;
use Filament\Panel;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/*
 * Component #7 — the streaming / causal-log viewer. The load-bearing work is the
 * pure StreamTimelinePresenter fold: it groups the append-only causal log by node,
 * preserves causal ordering, and surfaces void-edges as markers (including for a
 * missing/nulled predecessor) — all without ever leaking sw0: ciphertext. The
 * Livewire page render itself is deliberately NOT tested (Filament v5 + Testbench
 * getErrorBag()-null harness bug); the null→404 guard is tested below the render.
 */

class StreamGateUser extends Authenticatable {}

/** A StreamEventStore whose events() replays a scripted list — the read contract. */
function streamingViewerStore(array $events): StreamEventStore
{
    return new class($events) implements StreamEventStore
    {
        public function __construct(private array $events) {}

        public function record(string $runId, SwarmStreamEvent $event, int $ttlSeconds): void {}

        public function forget(string $runId): void {}

        public function events(string $runId): iterable
        {
            return $this->events;
        }
    };
}

// ---------------------------------------------------------------------------
// StreamTimelinePresenter — the pure fold, tested in isolation over payloads.
// ---------------------------------------------------------------------------

test('the presenter groups events by node in first-seen order, preserving each node order', function () {
    $timeline = StreamTimelinePresenter::build([
        ['id' => 'a', 'type' => 'swarm_stream_start', 'node_id' => null, 'timestamp' => 1],
        ['id' => 'b', 'type' => 'swarm_step_start', 'node_id' => 'n1', 'step_index' => 0, 'agent_class' => 'Writer', 'timestamp' => 2],
        ['id' => 'c', 'type' => 'swarm_step_end', 'node_id' => 'n1', 'step_index' => 0, 'agent_class' => 'Writer', 'timestamp' => 3],
        ['id' => 'd', 'type' => 'swarm_step_start', 'node_id' => 'n2', 'step_index' => 1, 'agent_class' => 'Reviewer', 'timestamp' => 4],
    ]);

    expect($timeline['event_count'])->toBe(4)
        ->and($timeline['void_edge_count'])->toBe(0)
        ->and($timeline['node_count'])->toBe(3);

    // Top-level bucket first (event a), then n1, then n2 — first-seen order.
    expect($timeline['nodes'][0]['is_top_level'])->toBeTrue()
        ->and($timeline['nodes'][0]['node_id'])->toBeNull()
        ->and($timeline['nodes'][1]['node_id'])->toBe('n1')
        ->and($timeline['nodes'][1]['event_count'])->toBe(2)
        ->and($timeline['nodes'][1]['events'][0]['event_id'])->toBe('b')
        ->and($timeline['nodes'][1]['events'][1]['event_id'])->toBe('c')
        ->and($timeline['nodes'][2]['node_id'])->toBe('n2');
});

test('the presenter annotates a voided event and emits a void marker beside its target', function () {
    $timeline = StreamTimelinePresenter::build([
        ['id' => 'target', 'type' => 'swarm_step_start', 'node_id' => 'n1', 'step_index' => 0, 'agent_class' => 'Writer', 'timestamp' => 1],
        ['id' => 'edge', 'type' => 'swarm_causal_void_edge', 'node_id' => null, 'void_type' => 'supersedes', 'target_event_id' => 'target', 'reason' => 'coordinator re-routed', 'timestamp' => 2],
    ]);

    expect($timeline['event_count'])->toBe(1)
        ->and($timeline['void_edge_count'])->toBe(1)
        // The void-edge sits WITH the node it voids (n1), not top-level.
        ->and($timeline['node_count'])->toBe(1)
        ->and($timeline['nodes'][0]['node_id'])->toBe('n1');

    $rows = $timeline['nodes'][0]['events'];

    // Row 0: the target, annotated as voided (warning).
    expect($rows[0]['event_id'])->toBe('target')
        ->and($rows[0]['marker'])->toContain('Voided')
        ->and($rows[0]['marker'])->toContain('superseded')
        ->and($rows[0]['marker'])->toContain('coordinator re-routed')
        ->and($rows[0]['marker_color'])->toBe('warning');

    // Row 1: the void-edge marker itself, pointing at the present target.
    expect($rows[1]['type'])->toBe('swarm_causal_void_edge')
        ->and($rows[1]['label'])->toBe('Void edge')
        ->and($rows[1]['summary'])->toContain('Voids')
        ->and($rows[1]['marker_color'])->toBe('warning');
});

test('the presenter surfaces a void-edge for a missing/nulled predecessor as a distinct danger marker', function () {
    // The target event is absent from the window (aged out / cold) — the causal-log
    // missing-predecessor case. It must be a visible marker, never silently dropped.
    $timeline = StreamTimelinePresenter::build([
        ['id' => 'present', 'type' => 'swarm_step_start', 'node_id' => 'n1', 'step_index' => 0, 'timestamp' => 1],
        ['id' => 'edge', 'type' => 'swarm_causal_void_edge', 'node_id' => 'n9', 'void_type' => 'abandons', 'target_event_id' => 'gone', 'reason' => 'branch cancelled', 'timestamp' => 2],
    ]);

    expect($timeline['void_edge_count'])->toBe(1);

    // The dangling marker falls to the void-edge's own node (n9), not the target's.
    $n9 = collect($timeline['nodes'])->firstWhere('node_id', 'n9');

    expect($n9)->not->toBeNull();
    $marker = $n9['events'][0];

    expect($marker['type'])->toBe('swarm_causal_void_edge')
        ->and($marker['summary'])->toContain('missing predecessor')
        ->and($marker['marker'])->toContain('abandoned')
        ->and($marker['marker'])->toContain('predecessor missing from window')
        ->and($marker['marker_color'])->toBe('danger');
});

test('the presenter resolves multiple void-edges on one event last-wins', function () {
    $timeline = StreamTimelinePresenter::build([
        ['id' => 't', 'type' => 'swarm_step_start', 'node_id' => 'n1', 'timestamp' => 1],
        ['id' => 'e1', 'type' => 'swarm_causal_void_edge', 'void_type' => 'replaces', 'target_event_id' => 't', 'reason' => 'first', 'timestamp' => 2],
        ['id' => 'e2', 'type' => 'swarm_causal_void_edge', 'void_type' => 'supersedes', 'target_event_id' => 't', 'reason' => 'second', 'timestamp' => 3],
    ]);

    // The target's annotation reflects the LAST edge (supersedes/second).
    expect($timeline['nodes'][0]['events'][0]['marker'])->toContain('superseded')
        ->and($timeline['nodes'][0]['events'][0]['marker'])->toContain('second')
        ->and($timeline['nodes'][0]['events'][0]['marker'])->not->toContain('first');
});

test('the presenter returns an empty timeline for no events', function () {
    $timeline = StreamTimelinePresenter::build([]);

    expect($timeline['event_count'])->toBe(0)
        ->and($timeline['void_edge_count'])->toBe(0)
        ->and($timeline['node_count'])->toBe(0)
        ->and($timeline['nodes'])->toBe([]);
});

test('the presenter tolerates malformed payload entries', function () {
    $timeline = StreamTimelinePresenter::build([
        'not-an-array',
        ['id' => 'ok', 'type' => 'swarm_text_end', 'node_id' => 'n1', 'timestamp' => 1],
        42,
    ]);

    expect($timeline['event_count'])->toBe(1)
        ->and($timeline['nodes'][0]['events'][0]['event_id'])->toBe('ok');
});

test('the presenter masks a still-sw0 payload leaf as defense in depth', function () {
    $timeline = StreamTimelinePresenter::build([
        ['id' => 'e', 'type' => 'swarm_step_start', 'node_id' => 'n1', 'input' => 'sw0:leaked-ciphertext', 'timestamp' => 1],
    ]);

    $payload = $timeline['nodes'][0]['events'][0]['payload'];

    expect($payload)->not->toContain('sw0:leaked-ciphertext')
        ->and($payload)->toContain('[unavailable]');
});

test('the presenter builds ciphertext-safe structural summaries and labels', function () {
    $timeline = StreamTimelinePresenter::build([
        ['id' => 'a', 'type' => 'swarm_stream_start', 'swarm_class' => 'App\\Swarms\\Demo', 'topology' => 'sequential', 'node_id' => null, 'timestamp' => 1],
        ['id' => 'b', 'type' => 'swarm_step_end', 'step_index' => 2, 'agent_class' => 'Writer', 'duration_ms' => 42, 'node_id' => 'n1', 'timestamp' => 2],
        ['id' => 'c', 'type' => 'swarm_text_delta', 'delta' => 'hello', 'node_id' => 'n1', 'timestamp' => 3],
    ]);

    $top = $timeline['nodes'][0]['events'][0];
    $n1 = $timeline['nodes'][1]['events'];

    expect($top['label'])->toBe('Stream start')
        ->and($top['summary'])->toContain('App\\Swarms\\Demo')
        ->and($top['summary'])->toContain('sequential')
        ->and($n1[0]['summary'])->toContain('Step 2 ended')
        ->and($n1[0]['summary'])->toContain('Writer')
        ->and($n1[0]['summary'])->toContain('42ms')
        ->and($n1[1]['summary'])->toContain('5 chars');
});

// ---------------------------------------------------------------------------
// ViewSwarmStream::resolve — the null→404 vs empty-state guard, below render.
// ---------------------------------------------------------------------------

test('resolve throws not-found for an unknown run with no events', function () {
    ViewSwarmStream::resolve(streamingViewerStore([]), null, 'ghost-run');
})->throws(ModelNotFoundException::class);

test('resolve returns an empty timeline (not 404) when the run exists but never streamed', function () {
    $resolved = ViewSwarmStream::resolve(
        streamingViewerStore([]),
        ['run_id' => 'r1', 'swarm_label' => 'Demo', 'status' => 'completed', 'status_color' => 'success', 'topology' => 'sequential'],
        'r1',
    );

    expect($resolved['run']['run_id'])->toBe('r1')
        ->and($resolved['timeline']['event_count'])->toBe(0)
        ->and($resolved['timeline']['nodes'])->toBe([]);
});

test('resolve renders the timeline for a purged run whose events still stream', function () {
    // No history row (null summary) but events present → render, never 404.
    $store = streamingViewerStore([
        new SwarmStreamStart(id: 'e1', runId: 'r1', swarmClass: 'App\\Swarms\\Demo', topology: 'sequential', input: 'hi', metadata: [], timestamp: 1),
    ]);

    $resolved = ViewSwarmStream::resolve($store, null, 'r1');

    expect($resolved['run']['run_id'])->toBe('r1')
        ->and($resolved['run']['status_color'])->toBe('gray')
        ->and($resolved['timeline']['event_count'])->toBe(1);
});

// ---------------------------------------------------------------------------
// Page surface: deny-by-default auth, navigation, routing, plugin registration.
// ---------------------------------------------------------------------------

test('the page gates access deny-by-default via the observability gate', function () {
    $this->actingAs(new StreamGateUser);
    config()->set('swarm-filament.authorization.ability', 'viewSwarmObservability');

    expect(ViewSwarmStream::canAccess())->toBeFalse();

    Gate::define('viewSwarmObservability', fn (): bool => true);
    expect(ViewSwarmStream::canAccess())->toBeTrue();
});

test('the page defers to Filament authorization when the ability is unset', function () {
    config()->set('swarm-filament.authorization.ability', null);

    expect(ViewSwarmStream::canAccess())->toBeTrue();
});

test('the page is not a navigation destination and is keyed by run id', function () {
    expect(ViewSwarmStream::shouldRegisterNavigation())->toBeFalse()
        ->and(ViewSwarmStream::getRoutePath(Panel::make()))->toContain('{record}');
});

test('the plugin registers the streaming viewer page', function () {
    $panel = Panel::make()->id('streaming-test');

    SwarmFilamentPlugin::make()->register($panel);

    expect($panel->getPages())->toContain(ViewSwarmStream::class);
});

// ---------------------------------------------------------------------------
// End-to-end data path: the real StreamEventStore contract → presenter.
// ---------------------------------------------------------------------------

test('a run timeline sourced through StreamEventStore groups nodes, marks voids, and never leaks sw0', function () {
    config()->set('swarm.persistence.driver', 'database');
    Artisan::call('migrate', ['--database' => 'testing']);

    $runId = 'streaming-e2e-1';
    $now = now('UTC');

    DB::table('swarm_run_histories')->insert([
        'run_id' => $runId,
        'swarm_class' => 'App\\Swarms\\Example',
        'topology' => 'sequential',
        'status' => 'running',
        'context' => json_encode([]),
        'metadata' => json_encode([]),
        'steps' => json_encode([]),
        'output' => null,
        'usage' => json_encode([]),
        'error' => null,
        'artifacts' => json_encode([]),
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $log = app(CausalLogStore::class);

    // A top-level start, then a step under node n1 that carries a defensively
    // sealed-looking payload leaf.
    $log->record($runId, new SwarmStreamStart(
        id: 'evt-start', runId: $runId, swarmClass: 'App\\Swarms\\Example', topology: 'sequential', input: 'go', metadata: [], timestamp: 10,
    ), 0);

    $step = (new SwarmStepStart(
        id: 'evt-step', runId: $runId, stepIndex: 0, agentClass: 'Writer', agent: 'writer', input: 'sw0:should-not-leak', timestamp: 20,
    ))->withNodeId('n1');
    $log->record($runId, $step, 0);

    // A real void-edge against the present step — appendVoidEdge validates the
    // target exists, so this exercises the true void path.
    $log->appendVoidEdge($runId, CausalVoidEdgeType::Supersedes, 'evt-step', 're-routed');

    // Read back through the PUBLIC StreamEventStore contract and fold.
    $timeline = StreamTimelinePresenter::present(app(StreamEventStore::class)->events($runId));

    expect($timeline['event_count'])->toBe(2)
        ->and($timeline['void_edge_count'])->toBe(1);

    // The step under n1 is annotated voided; a void marker sits in the same node.
    $n1 = collect($timeline['nodes'])->firstWhere('node_id', 'n1');
    expect($n1)->not->toBeNull();

    $voided = collect($n1['events'])->firstWhere('event_id', 'evt-step');
    expect($voided['marker'])->toContain('superseded');

    $marker = collect($n1['events'])->firstWhere('type', 'swarm_causal_void_edge');
    expect($marker)->not->toBeNull()
        ->and($marker['summary'])->toContain('Voids');

    // No sw0: ciphertext survives anywhere in the folded timeline.
    expect(json_encode($timeline))->not->toContain('sw0:should-not-leak')
        ->and(json_encode($timeline))->toContain('[unavailable]');
});
