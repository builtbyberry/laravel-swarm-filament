<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarmFilament\Support;

use BuiltByBerry\LaravelSwarm\Contracts\SwarmOperator;
use BuiltByBerry\LaravelSwarm\Responses\DurableRunDetail;
use BuiltByBerry\LaravelSwarmFilament\Resources\SwarmRunResource\Pages\ViewSwarmRun;

/**
 * Maps a durable run's {@see DurableRunDetail} (the read-only inspection seam
 * resolved once by {@see ViewSwarmRun})
 * into the render-ready "Durable execution" facet: what the run is blocked on
 * (waits), the signals it received, the progress markers it recorded, and its
 * retry / recovery / timeout status.
 *
 * READ-ONLY by construction. This facet presents durable state for observation
 * only — it emits no control affordances. Pause / resume / cancel / send-signal
 * are write verbs on core's {@see SwarmOperator}
 * and live in the paid operator console, never here.
 *
 * ## Sealed-value routing
 *
 * The structural columns — a wait/signal name, a status enum, a signal id, a
 * step index, an agent class, retry/recovery counts, and the timeout timestamps
 * — are framework-set scalars and render plain. The payload-bearing members that
 * could carry captured data — a wait's `reason` and `outcome`, a signal's
 * `payload`, a progress `detail` — are routed through the SINGLE companion sealed
 * chokepoint ({@see RunDisplayPresenter::renderField()} → {@see DisplayField}), so
 * a nested `sw0:` leaf is masked (or a bare-sealed scalar degrades to
 * `unavailable`) and ciphertext never renders raw — defense in depth, even though
 * these durable side-table columns are not themselves persisted sealed.
 *
 * Pure and container-free: it takes the already-assembled contract object and
 * never touches the store, the cipher, or Filament — so it is unit-testable
 * directly against a real {@see DurableRunDetail}.
 */
final class DurableExecutionPresenter
{
    /**
     * Build the read-only Durable execution facet, or null when the run is not a
     * durable run (no durable detail resolved) — so the caller shows the section
     * only for durable runs.
     *
     * @return array{status: ?string, retry_attempt: int, recovery_count: int, timeout: array{timed_out: bool, timed_out_at: ?string, timeout_at: ?string}, waits: list<array{name: ?string, status: ?string, reason: string, timeout_at: ?string, signal_id: ?int, outcome: string}>, signals: list<array{name: ?string, status: ?string, consumed_at: ?string, payload: string}>, progress: list<array{branch_id: ?string, step_index: ?int, agent_class: ?string, last_progress_at: ?string, detail: string}>}|null
     */
    public static function present(?DurableRunDetail $detail): ?array
    {
        if ($detail === null) {
            return null;
        }

        $run = is_array($detail->run) ? $detail->run : [];

        return [
            // Run-level durable status: the enum plus the recovery/retry counters —
            // all framework-set structural scalars, rendered plain.
            'status' => self::scalar($run['status'] ?? null),
            'retry_attempt' => self::count($run['retry_attempt'] ?? null),
            'recovery_count' => self::count($run['recovery_count'] ?? null),
            'timeout' => self::timeout($run),
            'waits' => self::waits($detail->waits),
            'signals' => self::signals($detail->signals),
            'progress' => self::progress($detail->progress),
        ];
    }

    /**
     * The run's timeout status: whether it timed out (a stamped `timed_out_at` or a
     * `timed_out` status), plus the timeout timestamps. All structural scalars.
     *
     * @param  array<string, mixed>  $run
     * @return array{timed_out: bool, timed_out_at: ?string, timeout_at: ?string}
     */
    private static function timeout(array $run): array
    {
        $timedOutAt = self::scalar($run['timed_out_at'] ?? null);

        return [
            'timed_out' => $timedOutAt !== null || ($run['status'] ?? null) === 'timed_out',
            'timed_out_at' => $timedOutAt,
            'timeout_at' => self::scalar($run['timeout_at'] ?? null),
        ];
    }

    /**
     * What the run is blocked on. `name`/`status`/`signal_id`/`timeout_at` are
     * structural scalars; the `reason` (a caller-supplied wait condition) and the
     * `outcome` (its resolution payload) are routed through the sealed chokepoint.
     *
     * @param  array<int, array<string, mixed>>  $waits
     * @return list<array{name: ?string, status: ?string, reason: string, timeout_at: ?string, signal_id: ?int, outcome: string}>
     */
    private static function waits(array $waits): array
    {
        $mapped = [];

        foreach ($waits as $wait) {
            if (! is_array($wait)) {
                continue;
            }

            $mapped[] = [
                'name' => self::scalar($wait['name'] ?? null),
                'status' => self::scalar($wait['status'] ?? null),
                'reason' => RunDisplayPresenter::renderField($wait, 'reason'),
                'timeout_at' => self::scalar($wait['timeout_at'] ?? null),
                'signal_id' => self::intOrNull($wait['signal_id'] ?? null),
                'outcome' => RunDisplayPresenter::renderField($wait, 'outcome'),
            ];
        }

        return $mapped;
    }

    /**
     * Signals the run received. `name`/`status`/`consumed_at` are structural
     * scalars; the `payload` (arbitrary caller data) is sealed-routed.
     *
     * @param  array<int, array<string, mixed>>  $signals
     * @return list<array{name: ?string, status: ?string, consumed_at: ?string, payload: string}>
     */
    private static function signals(array $signals): array
    {
        $mapped = [];

        foreach ($signals as $signal) {
            if (! is_array($signal)) {
                continue;
            }

            $mapped[] = [
                'name' => self::scalar($signal['name'] ?? null),
                'status' => self::scalar($signal['status'] ?? null),
                'consumed_at' => self::scalar($signal['consumed_at'] ?? null),
                'payload' => RunDisplayPresenter::renderField($signal, 'payload'),
            ];
        }

        return $mapped;
    }

    /**
     * Progress markers the run recorded. `branch_id`/`step_index`/`agent_class`/
     * `last_progress_at` are structural scalars; the progress `detail` (arbitrary
     * captured payload, keyed `progress` on the row) is sealed-routed.
     *
     * @param  array<int, array<string, mixed>>  $progress
     * @return list<array{branch_id: ?string, step_index: ?int, agent_class: ?string, last_progress_at: ?string, detail: string}>
     */
    private static function progress(array $progress): array
    {
        $mapped = [];

        foreach ($progress as $marker) {
            if (! is_array($marker)) {
                continue;
            }

            $mapped[] = [
                'branch_id' => self::scalar($marker['branch_id'] ?? null),
                'step_index' => self::intOrNull($marker['step_index'] ?? null),
                'agent_class' => self::scalar($marker['agent_class'] ?? null),
                'last_progress_at' => self::scalar($marker['last_progress_at'] ?? null),
                'detail' => RunDisplayPresenter::renderField($marker, 'progress'),
            ];
        }

        return $mapped;
    }

    private static function count(mixed $value): int
    {
        return is_numeric($value) ? max(0, (int) $value) : 0;
    }

    private static function intOrNull(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    private static function scalar(mixed $value): ?string
    {
        return is_scalar($value) ? (string) $value : null;
    }
}
