<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Audit\NoOpSwarmAuditSink;
use BuiltByBerry\LaravelSwarm\Contracts\ReadableSwarmAuditSink;
use BuiltByBerry\LaravelSwarm\Memory\MemorySnapshot;
use BuiltByBerry\LaravelSwarmFilament\Support\AuditTracePresenter;
use BuiltByBerry\LaravelSwarmFilament\Support\MemorySnapshotPresenter;
use BuiltByBerry\LaravelSwarmFilament\Support\StreamTimelinePresenter;

/*
 * Leak-proofing + degrade coverage for the three presenters the run page reuses
 * (StreamTimeline / Memory / Audit). Each carries its own defense-in-depth sw0:
 * masking beyond the DisplayField primitive. The run-centric redesign kept these
 * presenters but retired their test files with the old surfaces, so these restore
 * the net: a nested sw0: ciphertext leaf must never reach rendered output.
 */

// --- MemorySnapshotPresenter -----------------------------------------------

test('memory snapshot masks sealed values and never emits sw0: ciphertext', function () {
    $entry = fn (string $k, mixed $v): array => ['scope' => 'run', 'scope_id' => 'r', 'key' => $k, 'value' => $v];

    $snapshot = new MemorySnapshot(
        runId: 'run-1',
        stepIndex: 0,
        entries: [
            $entry('clean', 'hello'),
            $entry('sealed', 'sw0:AAAABBBBCCCC'),            // top-level ciphertext
            $entry('nested', ['inner' => 'sw0:DEADBEEF']),   // nested ciphertext (json would leak it)
            $entry('redacted', '[redacted]'),                // write-time redaction marker
            $entry('absent', null),                          // not captured
        ],
        toolCalls: [
            ['name' => 'search', 'arguments' => 'sw0:SEALEDARGS', 'result' => 'ok'],
        ],
    );

    $presented = MemorySnapshotPresenter::present($snapshot);
    $byKey = collect($presented['entries'])->keyBy('key');

    expect($byKey['clean']['value'])->toBe('hello')
        ->and($byKey['sealed']['value'])->toBe('unavailable')
        ->and($byKey['nested']['value'])->toBe('unavailable')   // containsSealed degrades the whole value
        ->and($byKey['redacted']['value'])->toBe('[redacted]')
        ->and($byKey['absent']['value'])->toBe('none')          // not-captured is distinct from redacted
        ->and($presented['tool_calls'][0]['arguments'])->toBe('unavailable')
        // the decisive guard: no ciphertext anywhere in the serialized output
        ->and(json_encode($presented))->not->toContain('sw0:');
});

// --- StreamTimelinePresenter -----------------------------------------------

test('stream timeline scrubs sealed leaves from event payloads', function () {
    $timeline = StreamTimelinePresenter::build([
        ['type' => 'swarm_step_start', 'id' => 'e1', 'node_id' => 'n1', 'step_index' => 0, 'timestamp' => 1, 'secret' => 'sw0:LEAKME'],
        ['type' => 'swarm_step_end', 'id' => 'e2', 'node_id' => 'n1', 'step_index' => 0, 'timestamp' => 2],
    ]);

    expect($timeline['node_count'])->toBe(1)
        ->and(json_encode($timeline))->not->toContain('sw0:')     // the leaf never renders
        ->and(json_encode($timeline))->toContain('unavailable');  // it degraded — not silently dropped
});

// --- AuditTracePresenter ---------------------------------------------------

test('audit trace lists a readable sink\'s records and minimizes the payload', function () {
    $sink = new class implements ReadableSwarmAuditSink
    {
        public function emit(string $category, array $payload): void {}

        public function forRun(string $runId): iterable
        {
            return [
                // even if a sink hands back a sealed payload, the presenter surfaces
                // metadata only — the envelope is never carried into the timeline.
                ['category' => 'run.started', 'occurred_at' => '2026-07-09T01:00:00+00:00', 'run_id' => $runId, 'payload' => ['secret' => 'sw0:NOPE']],
                ['category' => 'run.completed', 'occurred_at' => '2026-07-09T01:00:05+00:00', 'run_id' => $runId],
            ];
        }
    };

    $trace = AuditTracePresenter::present($sink, 'run-1');

    expect($trace['readable'])->toBeTrue()
        ->and($trace['records'])->toHaveCount(2)
        ->and($trace['records'][0])->toBe(['category' => 'run.started', 'occurred_at' => '2026-07-09T01:00:00+00:00', 'run_id' => 'run-1'])
        ->and($trace['records'][0])->not->toHaveKey('payload')   // payload minimization
        ->and(json_encode($trace))->not->toContain('sw0:');
});

test('audit trace explains the empty state when no readable sink is bound', function () {
    $trace = AuditTracePresenter::present(new NoOpSwarmAuditSink, 'run-1');

    expect($trace['readable'])->toBeFalse()
        ->and($trace['reason'])->toBe('noop')
        ->and($trace['records'])->toBe([])
        ->and($trace['notes'][0])->toContain('No readable audit sink');
});
