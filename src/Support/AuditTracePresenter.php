<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarmFilament\Support;

use BuiltByBerry\LaravelSwarm\Audit\NoOpSwarmAuditSink;
use BuiltByBerry\LaravelSwarm\Contracts\ReadableSwarmAuditSink;
use BuiltByBerry\LaravelSwarm\Contracts\SwarmAuditSink;
use Throwable;

/**
 * Assembles the audit-trace timeline for a run from the application's bound
 * {@see SwarmAuditSink}, mirroring how `php artisan swarm:trace` resolves its
 * sink-side records.
 *
 * The audit TRAIL (every evidence record a run emitted) is served ONLY by an
 * application sink that implements the optional {@see ReadableSwarmAuditSink}
 * contract. Core's shipped default is {@see NoOpSwarmAuditSink}, which stores
 * nothing — so out of the box this surface renders a clean empty-state that
 * explains how to bind a readable sink, rather than an empty table with no
 * context.
 *
 * Three sink conditions are distinguished ({@see classify()}):
 *
 * - **noop** — the bound sink is the no-op; nothing is stored;
 * - **not_readable** — a real sink is bound but does not implement
 *   {@see ReadableSwarmAuditSink}, so its records cannot be listed;
 * - **readable** — the sink can list a run's evidence via `forRun()`.
 *
 * Degrade-safe: a `forRun()` that throws is caught and surfaced as a note with a
 * partial (or empty) timeline — a broken sink never 500s the page. Payload-
 * minimized: the timeline carries evidence metadata (category, timestamp, run)
 * only, never the full evidence envelope.
 *
 * Pure: it takes the bound sink and never touches Filament, so it is unit-testable
 * directly with a scripted sink.
 */
final class AuditTracePresenter
{
    /**
     * The default cap on sink-side records consumed from `forRun()`, guarding
     * against an unbounded read on a long-lived run (mirrors swarm:trace's
     * `--limit` default).
     */
    public const DEFAULT_LIMIT = 1000;

    /**
     * Classify the bound sink's readability without consuming any records.
     *
     * @return array{readable: bool, reason: 'noop'|'not_readable'|'readable', sink_class: string}
     */
    public static function classify(SwarmAuditSink $sink): array
    {
        $class = $sink::class;

        if ($sink instanceof NoOpSwarmAuditSink) {
            return ['readable' => false, 'reason' => 'noop', 'sink_class' => $class];
        }

        if ($sink instanceof ReadableSwarmAuditSink) {
            return ['readable' => true, 'reason' => 'readable', 'sink_class' => $class];
        }

        return ['readable' => false, 'reason' => 'not_readable', 'sink_class' => $class];
    }

    /**
     * Build the render-ready timeline for a run (or the empty-state when no run is
     * given / no readable sink is bound).
     *
     * @return array{run_id: ?string, readable: bool, reason: 'noop'|'not_readable'|'readable', sink_class: string, records: list<array{category: string, occurred_at: ?string, run_id: ?string}>, truncated: bool, error: ?string, notes: list<string>}
     */
    public static function present(SwarmAuditSink $sink, ?string $runId, int $limit = self::DEFAULT_LIMIT): array
    {
        $runId = self::normalizeRun($runId);
        $limit = max(1, $limit);
        $classification = self::classify($sink);

        $records = [];
        $error = null;
        $truncated = false;

        if ($runId !== null && $classification['readable'] && $sink instanceof ReadableSwarmAuditSink) {
            try {
                foreach ($sink->forRun($runId) as $record) {
                    if (! is_array($record)) {
                        continue;
                    }

                    if (count($records) >= $limit) {
                        $truncated = true;

                        break;
                    }

                    $records[] = self::record($record);
                }
            } catch (Throwable $exception) {
                // A broken/degraded sink surfaces as a note with a partial result —
                // never an uncaught error that 500s the page.
                $error = $exception->getMessage();
            }

            self::sortByOccurredAt($records);
        }

        return [
            'run_id' => $runId,
            'readable' => $classification['readable'],
            'reason' => $classification['reason'],
            'sink_class' => $classification['sink_class'],
            'records' => $records,
            'truncated' => $truncated,
            'error' => $error,
            'notes' => self::notes($classification, $runId, $error, $truncated),
        ];
    }

    /**
     * Map a sink record to timeline metadata. The evidence `payload` is
     * deliberately omitted (payload minimization) — the timeline surfaces category,
     * timestamp, and run only.
     *
     * @param  array<string, mixed>  $record
     * @return array{category: string, occurred_at: ?string, run_id: ?string}
     */
    private static function record(array $record): array
    {
        return [
            'category' => self::scalar($record['category'] ?? null) ?? 'unknown',
            'occurred_at' => self::scalar($record['occurred_at'] ?? null),
            'run_id' => self::scalar($record['run_id'] ?? null),
        ];
    }

    /**
     * Stable sort by `occurred_at` ascending; records without a timestamp sort last
     * (mirrors swarm:trace's timeline ordering).
     *
     * @param  list<array{category: string, occurred_at: ?string, run_id: ?string}>  $records
     */
    private static function sortByOccurredAt(array &$records): void
    {
        usort($records, static function (array $a, array $b): int {
            $left = $a['occurred_at'];
            $right = $b['occurred_at'];

            if ($left === $right) {
                return 0;
            }

            if ($left === null) {
                return 1;
            }

            if ($right === null) {
                return -1;
            }

            return strcmp($left, $right);
        });
    }

    /**
     * The operator-facing guidance for the current sink/run condition, so the
     * empty-state explains itself instead of rendering a bare empty timeline.
     *
     * @param  array{readable: bool, reason: 'noop'|'not_readable'|'readable', sink_class: string}  $classification
     * @return list<string>
     */
    private static function notes(array $classification, ?string $runId, ?string $error, bool $truncated): array
    {
        $notes = [];

        if ($classification['reason'] === 'noop') {
            $notes[] = 'No readable audit sink is bound. The default sink stores nothing, so there is no audit trail to show. Bind a SwarmAuditSink that implements ReadableSwarmAuditSink to surface a run\'s emitted evidence here.';
        } elseif ($classification['reason'] === 'not_readable') {
            $notes[] = sprintf(
                'The bound audit sink (%s) does not implement ReadableSwarmAuditSink, so its records cannot be listed. Implement the contract on your sink to surface the audit trail here.',
                $classification['sink_class'],
            );
        } elseif ($runId === null) {
            $notes[] = 'Provide a run id to load its audit trail.';
        }

        if ($error !== null) {
            $notes[] = "The audit sink failed while reading this run — the trail is partial or empty. Error: {$error}";
        }

        if ($truncated) {
            $notes[] = 'The sink returned more records than the read limit; the trail was truncated.';
        }

        return $notes;
    }

    private static function normalizeRun(?string $runId): ?string
    {
        if ($runId === null) {
            return null;
        }

        $runId = trim($runId);

        return $runId === '' ? null : $runId;
    }

    private static function scalar(mixed $value): ?string
    {
        return is_scalar($value) ? (string) $value : null;
    }
}
