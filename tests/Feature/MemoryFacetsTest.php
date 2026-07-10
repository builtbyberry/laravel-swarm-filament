<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Contracts\SnapshotsMemory;
use BuiltByBerry\LaravelSwarm\Memory\MemorySnapshot;
use BuiltByBerry\LaravelSwarmFilament\Support\MemoryFacets;

/*
 * MemoryFacets folds a run's frozen memory snapshots onto its steps: per step, the
 * keys it *wrote* (diffed against the previous snapshot), the entries it could
 * see, and the tools it called. This is the logic that replaced the retired global
 * "Memory Snapshots" list — it had no coverage after the redesign.
 */

function fakeSnapshotStore(array $snapshots): SnapshotsMemory
{
    return new class($snapshots) implements SnapshotsMemory
    {
        /** @param array<int, MemorySnapshot> $snapshots */
        public function __construct(private array $snapshots) {}

        public function allForRun(string $runId): array
        {
            return $this->snapshots;
        }

        public function snapshot(string $runId, int $stepIndex, ?array $entries = null): MemorySnapshot
        {
            throw new RuntimeException('unused');
        }

        public function appendToolCall(MemorySnapshot $snapshot, array $toolCall): MemorySnapshot
        {
            throw new RuntimeException('unused');
        }

        public function resetToolCalls(MemorySnapshot $snapshot): MemorySnapshot
        {
            throw new RuntimeException('unused');
        }

        public function find(string $runId, int $stepIndex): ?MemorySnapshot
        {
            return null;
        }
    };
}

function memEntry(string $key, string $value): array
{
    return ['scope' => 'run', 'scope_id' => 'r', 'key' => $key, 'value' => $value];
}

test('forRun reports only newly written or changed keys per step', function () {
    $store = fakeSnapshotStore([
        new MemorySnapshot(runId: 'r', stepIndex: 0, entries: [memEntry('a', '1'), memEntry('b', '2')]),
        new MemorySnapshot(runId: 'r', stepIndex: 1, entries: [
            memEntry('a', '1'),   // unchanged — must NOT be reported as written
            memEntry('b', '9'),   // changed
            memEntry('c', '3'),   // new
        ], toolCalls: [['name' => 'search', 'arguments' => 'q', 'result' => 'ok']]),
    ]);

    $facets = MemoryFacets::forRun($store, 'r');

    expect($facets[0]['wrote'])->toBe(['a', 'b'])
        ->and($facets[1]['wrote'])->toBe(['b', 'c'])      // 'a' unchanged, excluded
        ->and($facets[0]['tools'])->toBe([])
        ->and($facets[1]['tools'])->toBe(['search'])
        ->and(collect($facets[1]['entries'])->pluck('key')->all())->toBe(['a', 'b', 'c']);
});

test('forRun sorts snapshots by step index before diffing', function () {
    // Deliberately out of order: forRun sorts by stepIndex, so the step-1 diff is
    // against step 0 — not "whatever came first in the list".
    $store = fakeSnapshotStore([
        new MemorySnapshot(runId: 'r', stepIndex: 1, entries: [memEntry('a', '1'), memEntry('b', '2')]),
        new MemorySnapshot(runId: 'r', stepIndex: 0, entries: [memEntry('a', '1')]),
    ]);

    $facets = MemoryFacets::forRun($store, 'r');

    expect($facets[0]['wrote'])->toBe(['a'])
        ->and($facets[1]['wrote'])->toBe(['b']);   // only b is new vs step 0
});
