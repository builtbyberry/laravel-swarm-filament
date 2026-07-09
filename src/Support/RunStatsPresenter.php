<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarmFilament\Support;

use BuiltByBerry\LaravelSwarmFilament\Models\SwarmRun;

/**
 * Aggregates the runs-index headline metrics from the plaintext DISPLAY_COLUMNS
 * ONLY (status, the created/finished timestamps, and the token-count summary) —
 * never a sealed column, so the strip reads the same leak-safe surface the index
 * table already binds ({@see SwarmRun}).
 *
 * Volume metrics (total, needs-attention) are all-time COUNTs — cheap on any size
 * history. Latency and token throughput are inherently recent-health signals, so
 * they are computed over a bounded window of the most recent runs ({@see RECENT})
 * from a single read — the strip stays three cheap queries even on a large table,
 * never an unbounded pluck or an N+1.
 *
 * Cost is intentionally absent: the persisted usage carries token counts but no
 * model/price data, and the package ships no pricing table — a dollar figure
 * cannot be derived without a model→price mapping (a future config surface), so
 * tokens are shown rather than an invented cost.
 *
 * Container-free and DB-backed, so it is verified directly with seeded rows
 * (real-contract data-path) rather than through a widget/page render.
 */
final class RunStatsPresenter
{
    /**
     * Statuses that mean "a human should look" — the failure family the index
     * badge also flags. Kept as a list so a status the store never emits simply
     * contributes zero.
     *
     * @var list<string>
     */
    private const ATTENTION = ['failed', 'dead_letter', 'timed_out', 'errored'];

    /** Bounded window (most-recent runs) for the recent-health tiles. */
    public const RECENT = 250;

    /**
     * @return array{total: int, needs_attention: int, p50_latency: ?string, tokens: int, window: int}
     */
    public static function present(): array
    {
        $total = SwarmRun::query()->count();
        $needsAttention = SwarmRun::query()->whereIn('status', self::ATTENTION)->count();

        // One bounded read of the most recent runs powers both recent-health tiles.
        $recent = SwarmRun::query()
            ->orderByDesc('created_at')
            ->limit(self::RECENT)
            ->get();

        // Single pass: sum token throughput and collect finished-run durations.
        $tokens = 0;
        $durations = [];
        foreach ($recent as $run) {
            $usage = is_array($run->usage) ? $run->usage : [];
            $tokens += (is_numeric($usage['prompt_tokens'] ?? null) ? (int) $usage['prompt_tokens'] : 0)
                + (is_numeric($usage['completion_tokens'] ?? null) ? (int) $usage['completion_tokens'] : 0);

            $start = $run->created_at;
            $end = $run->finished_at;
            if ($start !== null && $end !== null) {
                $seconds = $end->getTimestamp() - $start->getTimestamp();
                if ($seconds >= 0) {
                    $durations[] = $seconds;
                }
            }
        }
        sort($durations);

        return [
            'total' => $total,
            'needs_attention' => $needsAttention,
            'p50_latency' => $durations === []
                ? null
                : self::humanizeDuration($durations[intdiv(count($durations), 2)]),
            'tokens' => $tokens,
            'window' => min(self::RECENT, $recent->count()),
        ];
    }

    /**
     * Seconds → a compact human latency: "45s", "1m 30s", "1h 2m".
     */
    public static function humanizeDuration(int $seconds): string
    {
        $seconds = max(0, $seconds);

        if ($seconds < 60) {
            return $seconds.'s';
        }

        if ($seconds < 3600) {
            return intdiv($seconds, 60).'m '.($seconds % 60).'s';
        }

        return intdiv($seconds, 3600).'h '.intdiv($seconds % 3600, 60).'m';
    }
}
