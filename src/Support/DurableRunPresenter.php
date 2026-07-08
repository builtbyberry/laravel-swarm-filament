<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarmFilament\Support;

use BuiltByBerry\LaravelSwarm\Contracts\InspectsDurableRuns;
use BuiltByBerry\LaravelSwarm\Responses\DurableRunDetail;
use Filament\Infolists\Components\KeyValueEntry;

/**
 * Maps an {@see InspectsDurableRuns::inspect()}
 * {@see DurableRunDetail} into a flat, render-ready array for the durable-run
 * inspector infolist.
 *
 * The detail is already display-decrypted per row by core (the `open()`-backed
 * evidence path that honors `swarm.persistence.decrypt_failure_policy`): each
 * sealed member arrives as a `value` plus a companion `<field>_available` flag.
 * This presenter turns those pairs into render-ready strings through the single
 * {@see DisplayField} rule, distinguishing three states everywhere:
 *
 * - **value** — decrypted and present;
 * - **`unavailable`** — present but undecryptable, or still `sw0:`-sealed
 *   (defense in depth) — never the raw ciphertext;
 * - **`none`** — absent/empty (not captured or not yet produced), distinct from
 *   an undecryptable field.
 *
 * The invariant is leak-proofing: no `sw0:` ciphertext ever escapes. Every
 * string — including nested values inside the JSON-ish maps (branch/child
 * failures, wait outcomes, signal payloads, recorded progress, run details) —
 * is routed through {@see DisplayField}, so a sealed value nested anywhere in a
 * map is masked, not serialized.
 *
 * Pure and container-free: it takes the assembled DTO and never touches the
 * cipher, the store, or Filament — so it is unit-testable directly.
 */
final class DurableRunPresenter
{
    private const UNAVAILABLE = 'unavailable';

    private const NONE = 'none';

    /**
     * @return array{
     *     run_id: string,
     *     summary: array<string, string>,
     *     lifecycle: array<string, string>,
     *     labels: array<string, string>,
     *     details: array<string, string>,
     *     branches: list<array<string, string>>,
     *     children: list<array<string, string>>,
     *     node_outputs: list<array<string, string>>,
     *     waits: list<array<string, string>>,
     *     signals: list<array<string, string>>,
     *     progress: list<array<string, string>>,
     *     history: array<string, mixed>|null,
     * }
     */
    public static function present(DurableRunDetail $detail): array
    {
        $run = is_array($detail->run) ? $detail->run : [];

        return [
            'run_id' => $detail->runId,
            'summary' => self::summary($detail->runId, $run),
            'lifecycle' => self::lifecycle($run),
            'labels' => self::map($detail->labels),
            'details' => self::map($detail->details),
            'branches' => self::branches($detail->branches),
            'children' => self::children($detail->children),
            'node_outputs' => self::nodeOutputs($detail->hierarchicalNodeOutputs),
            'waits' => self::waits($detail->waits),
            'signals' => self::signals($detail->signals),
            'progress' => self::progress($detail->progress),
            'history' => is_array($detail->history) ? RunDisplayPresenter::present($detail->history) : null,
        ];
    }

    /**
     * The at-a-glance run identity — plaintext columns, never sealed.
     *
     * @param  array<string, mixed>  $run
     * @return array<string, string>
     */
    private static function summary(string $runId, array $run): array
    {
        return [
            'run_id' => $runId,
            'swarm_class' => self::value($run['swarm_class'] ?? null),
            'topology' => self::value($run['topology'] ?? null),
            'status' => self::value($run['status'] ?? null),
            'execution_mode' => self::value($run['execution_mode'] ?? null),
            'coordination_profile' => self::value($run['coordination_profile'] ?? null),
            'created_at' => self::value($run['created_at'] ?? null),
            'finished_at' => self::value($run['finished_at'] ?? null),
        ];
    }

    /**
     * The lifecycle markers — step counters, attempts, lease, and the
     * pause / cancel / timeout / wait / retry timestamps — as a display map.
     *
     * @param  array<string, mixed>  $run
     * @return array<string, string>
     */
    private static function lifecycle(array $run): array
    {
        $keys = [
            'total_steps',
            'current_step_index',
            'next_step_index',
            'attempts',
            'recovery_count',
            'last_recovered_at',
            'lease_acquired_at',
            'leased_until',
            'timeout_at',
            'pause_requested_at',
            'paused_at',
            'resumed_at',
            'cancel_requested_at',
            'cancelled_at',
            'timed_out_at',
            'wait_reason',
            'waiting_since',
            'wait_timeout_at',
            'last_progress_at',
            'retry_attempt',
            'next_retry_at',
            'parent_run_id',
            'updated_at',
        ];

        $lifecycle = [];

        foreach ($keys as $key) {
            $lifecycle[$key] = self::value($run[$key] ?? null);
        }

        return $lifecycle;
    }

    /**
     * @param  array<int, array<string, mixed>>  $branches
     * @return list<array<string, string>>
     */
    private static function branches(array $branches): array
    {
        return self::rows($branches, static fn (array $b): array => [
            'branch_id' => self::value($b['branch_id'] ?? null),
            'step_index' => self::value($b['step_index'] ?? null),
            'node_id' => self::value($b['node_id'] ?? null),
            'parent_node_id' => self::value($b['parent_node_id'] ?? null),
            'agent_class' => self::value($b['agent_class'] ?? null),
            'status' => self::value($b['status'] ?? null),
            'input' => self::sealed($b, 'input'),
            'output' => self::sealed($b, 'output'),
            'attempts' => self::value($b['attempts'] ?? null),
            'duration_ms' => self::value($b['duration_ms'] ?? null),
            'failure' => self::json($b['failure'] ?? null),
            'started_at' => self::value($b['started_at'] ?? null),
            'finished_at' => self::value($b['finished_at'] ?? null),
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $children
     * @return list<array<string, string>>
     */
    private static function children(array $children): array
    {
        return self::rows($children, static fn (array $c): array => [
            'child_run_id' => self::value($c['child_run_id'] ?? null),
            'child_swarm_class' => self::value($c['child_swarm_class'] ?? null),
            'wait_name' => self::value($c['wait_name'] ?? null),
            'status' => self::value($c['status'] ?? null),
            // The child context arrives flat as `context_payload` + `context_available`.
            'context' => self::sealed([
                'context' => $c['context_payload'] ?? null,
                'context_available' => $c['context_available'] ?? true,
            ], 'context'),
            'output' => self::sealed($c, 'output'),
            'failure' => self::json($c['failure'] ?? null),
            'dispatched_at' => self::value($c['dispatched_at'] ?? null),
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $outputs
     * @return list<array<string, string>>
     */
    private static function nodeOutputs(array $outputs): array
    {
        return self::rows($outputs, static fn (array $o): array => [
            'node_id' => self::value($o['node_id'] ?? null),
            'output' => self::sealed($o, 'output'),
            'created_at' => self::value($o['created_at'] ?? null),
            'expires_at' => self::value($o['expires_at'] ?? null),
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $waits
     * @return list<array<string, string>>
     */
    private static function waits(array $waits): array
    {
        return self::rows($waits, static fn (array $w): array => [
            'name' => self::value($w['name'] ?? null),
            'status' => self::value($w['status'] ?? null),
            'reason' => self::value($w['reason'] ?? null),
            'timeout_at' => self::value($w['timeout_at'] ?? null),
            'outcome' => self::json($w['outcome'] ?? null),
            'finished_at' => self::value($w['finished_at'] ?? null),
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $signals
     * @return list<array<string, string>>
     */
    private static function signals(array $signals): array
    {
        return self::rows($signals, static fn (array $s): array => [
            'name' => self::value($s['name'] ?? null),
            'status' => self::value($s['status'] ?? null),
            'payload' => self::json($s['payload'] ?? null),
            'idempotency_key' => self::value($s['idempotency_key'] ?? null),
            'consumed_step_index' => self::value($s['consumed_step_index'] ?? null),
            'consumed_at' => self::value($s['consumed_at'] ?? null),
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $progress
     * @return list<array<string, string>>
     */
    private static function progress(array $progress): array
    {
        return self::rows($progress, static fn (array $p): array => [
            'branch_id' => self::value($p['branch_id'] ?? null),
            'step_index' => self::value($p['step_index'] ?? null),
            'agent_class' => self::value($p['agent_class'] ?? null),
            'progress' => self::json($p['progress'] ?? null),
            'last_progress_at' => self::value($p['last_progress_at'] ?? null),
        ]);
    }

    /**
     * Map each row of a list through $mapper, skipping any non-array entry.
     *
     * @param  array<int, mixed>  $rows
     * @param  callable(array<string, mixed>): array<string, string>  $mapper
     * @return list<array<string, string>>
     */
    private static function rows(array $rows, callable $mapper): array
    {
        $mapped = [];

        foreach ($rows as $row) {
            if (is_array($row)) {
                $mapped[] = $mapper($row);
            }
        }

        return $mapped;
    }

    /**
     * Render a flat `<field>` / `<field>_available` sealed pair, mapping the
     * three {@see DisplayField} outcomes to a display string.
     *
     * @param  array<string, mixed>  $row
     */
    private static function sealed(array $row, string $field): string
    {
        $displayField = DisplayField::fromRow($row, $field);

        if ($displayField->isAvailable()) {
            return (string) $displayField->value;
        }

        // An explicit false flag (or a masked sw0: value) means undecryptable;
        // otherwise the field was simply absent/empty.
        return $displayField->available === false ? self::UNAVAILABLE : self::NONE;
    }

    /**
     * A plaintext scalar (status, timestamp, counter). Absent/empty renders as
     * `none`; a value is still routed through {@see DisplayField} so a stray
     * `sw0:` string can never leak from a column that should hold plaintext.
     */
    private static function value(mixed $value): string
    {
        if ($value === null || $value === '') {
            return self::NONE;
        }

        return self::sealed(['v' => $value, 'v_available' => true], 'v');
    }

    /**
     * Render a JSON-ish map/list (failures, outcomes, payloads, progress,
     * details) as a compact JSON string, recursively masking any nested value
     * that is still `sw0:` ciphertext so nothing sealed is ever serialized.
     * Empty/absent renders as `none`.
     */
    private static function json(mixed $value): string
    {
        if ($value === null || $value === [] || $value === '') {
            return self::NONE;
        }

        return (string) json_encode(self::sanitize($value));
    }

    /**
     * A display map for {@see KeyValueEntry}:
     * every value rendered leak-safe (a sealed nested value is masked).
     *
     * @param  array<string, mixed>  $map
     * @return array<string, string>
     */
    private static function map(array $map): array
    {
        $rendered = [];

        foreach ($map as $key => $value) {
            $rendered[(string) $key] = is_scalar($value) || $value === null
                ? self::value($value)
                : self::json($value);
        }

        return $rendered;
    }

    /**
     * Recursively replace any string that is still `sw0:` ciphertext with the
     * unavailable marker before serialization. Leaf strings are routed through
     * {@see DisplayField} so the sealed-prefix rule lives in ONE place.
     */
    private static function sanitize(mixed $value): mixed
    {
        if (is_string($value)) {
            $displayField = DisplayField::fromRow(['v' => $value, 'v_available' => true], 'v');

            return $displayField->isAvailable() ? $displayField->value : self::UNAVAILABLE;
        }

        if (is_array($value)) {
            return array_map(static fn (mixed $item): mixed => self::sanitize($item), $value);
        }

        return $value;
    }
}
