<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarmFilament\Support;

use BuiltByBerry\LaravelSwarm\Contracts\SnapshotsMemory;

/**
 * Joins a run's frozen memory snapshots to its steps, so the run page can show
 * "what this step remembered / wrote" in context on the flow — the thing that
 * used to live in a divorced global "Memory Snapshots" list.
 *
 * Pure aside from the injected read contract. Per step it surfaces: the keys
 * written/changed at that step (diffed against the previous snapshot), the
 * agent-visible entries frozen there, and any tool calls. Values are already
 * redaction/sw0:-safe via {@see MemorySnapshotPresenter}.
 */
final class MemoryFacets
{
    /**
     * @return array<int, array{wrote: list<string>, entries: list<array{key: ?string, value: string, scope: ?string}>, tools: list<string>}>
     */
    public static function forRun(SnapshotsMemory $store, string $runId): array
    {
        $snapshots = $store->allForRun($runId);
        usort($snapshots, static fn ($a, $b): int => $a->stepIndex <=> $b->stepIndex);

        $facets = [];
        $previous = [];

        foreach ($snapshots as $snapshot) {
            $presented = MemorySnapshotPresenter::present($snapshot);
            $entries = is_array($presented['entries'] ?? null) ? $presented['entries'] : [];

            $current = [];
            $rows = [];
            foreach ($entries as $entry) {
                if (! is_array($entry)) {
                    continue;
                }
                $key = is_string($entry['key'] ?? null) ? $entry['key'] : null;
                $value = is_string($entry['value'] ?? null) ? $entry['value'] : '';
                $rows[] = ['key' => $key, 'value' => $value, 'scope' => is_string($entry['scope'] ?? null) ? $entry['scope'] : null];
                if ($key !== null) {
                    $current[$key] = $value;
                }
            }

            $wrote = [];
            foreach ($current as $key => $value) {
                if (! array_key_exists($key, $previous) || $previous[$key] !== $value) {
                    $wrote[] = $key;
                }
            }

            $tools = [];
            foreach ((is_array($presented['tool_calls'] ?? null) ? $presented['tool_calls'] : []) as $call) {
                if (is_array($call) && is_string($call['name'] ?? null) && $call['name'] !== '') {
                    $tools[] = $call['name'];
                }
            }

            $facets[$snapshot->stepIndex] = ['wrote' => $wrote, 'entries' => $rows, 'tools' => $tools];
            $previous = $current;
        }

        return $facets;
    }
}
