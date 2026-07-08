<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarmFilament\Support;

use BuiltByBerry\LaravelSwarm\Memory\MemorySnapshot;

/**
 * Maps a frozen {@see MemorySnapshot} into a flat, render-ready array for the
 * memory-snapshot infolist (summary + memory entries + tool calls).
 *
 * Every entry value — and every tool-call argument/result — is routed through
 * {@see DisplayField} here so the infolist renders already-safe strings and the
 * leak-proofing lives in ONE tested place. Four display states are distinguished
 * for a value:
 *
 * - **value** — a real, present value (arrays are rendered as compact JSON);
 * - **`[redacted]`** — a capture policy redacted the value at write time,
 *   replacing scalars with the redaction sentinel; rendered as the clear marker
 *   rather than a guessed value;
 * - **`unavailable`** — a value that still looks like `sw0:` ciphertext, masked
 *   as defense in depth even though memory is not sealed at rest — the companion
 *   never leaks ciphertext whatever an upstream read does;
 * - **`none`** — absent/empty (a not-captured value), distinct from a redacted one.
 *
 * Pure and container-free: it takes the already-frozen snapshot value object and
 * never touches the cipher, a store, or Filament — so it is unit-testable directly.
 */
final class MemorySnapshotPresenter
{
    /**
     * Core's memory redaction sentinel. Inlined as a literal (pinned by a test)
     * rather than importing the `@internal` `SwarmCapture` — a companion never
     * couples to core internals (records 629/632), the same discipline
     * {@see DisplayField} applies to the `sw0:` prefix.
     */
    private const REDACTED = '[redacted]';

    private const UNAVAILABLE = 'unavailable';

    private const NONE = 'none';

    /**
     * @return array{run_id: string, step_index: int, recorded_at: ?string, updated_at: ?string, entry_count: int, tool_call_count: int, entries: list<array{scope: string, scope_id: string, key: string, value: string}>, tool_calls: list<array{name: string, arguments: string, result: string}>}
     */
    public static function present(MemorySnapshot $snapshot): array
    {
        $entries = self::entries($snapshot->entries);
        $toolCalls = self::toolCalls($snapshot->toolCalls);

        return [
            'run_id' => $snapshot->runId,
            'step_index' => $snapshot->stepIndex,
            'recorded_at' => $snapshot->recordedAt,
            'updated_at' => $snapshot->updatedAt,
            'entry_count' => count($entries),
            'tool_call_count' => count($toolCalls),
            'entries' => $entries,
            'tool_calls' => $toolCalls,
        ];
    }

    /**
     * @param  array<int, mixed>  $entries
     * @return list<array{scope: string, scope_id: string, key: string, value: string}>
     */
    private static function entries(array $entries): array
    {
        $mapped = [];

        foreach ($entries as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $mapped[] = [
                'scope' => self::label($entry['scope'] ?? null),
                'scope_id' => self::label($entry['scope_id'] ?? null),
                'key' => self::label($entry['key'] ?? null),
                'value' => self::renderValue($entry['value'] ?? null),
            ];
        }

        return $mapped;
    }

    /**
     * @param  array<int, mixed>  $toolCalls
     * @return list<array{name: string, arguments: string, result: string}>
     */
    private static function toolCalls(array $toolCalls): array
    {
        $mapped = [];

        foreach ($toolCalls as $call) {
            if (! is_array($call)) {
                continue;
            }

            $mapped[] = [
                'name' => self::label($call['name'] ?? null),
                'arguments' => self::renderValue($call['arguments'] ?? null),
                'result' => self::renderValue($call['result'] ?? null),
            ];
        }

        return $mapped;
    }

    /**
     * Map a raw memory/tool value to its display string across the four states.
     * Ciphertext never escapes: a still-`sw0:` value is flagged unavailable by
     * {@see DisplayField}.
     */
    private static function renderValue(mixed $raw): string
    {
        // Absent/empty — a not-captured value — is distinct from a redacted one.
        if ($raw === null) {
            return self::NONE;
        }

        // Redacted-at-write: the policy stored the sentinel in place of the
        // secret. Render the clear marker, never a reconstructed value.
        if ($raw === self::REDACTED) {
            return self::REDACTED;
        }

        // Route through DisplayField for the sw0: defense-in-depth + stringify:
        // a value that still looks like ciphertext is masked, never leaked.
        $field = DisplayField::fromRow(['value' => $raw], 'value');

        return $field->isAvailable() ? (string) $field->value : self::UNAVAILABLE;
    }

    /**
     * A scope/scope_id/key/name cell: the scalar string, or a dash placeholder
     * for an absent/non-scalar addressing field (which should never happen for a
     * well-formed snapshot, but must not throw if it does).
     */
    private static function label(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '—';
    }
}
