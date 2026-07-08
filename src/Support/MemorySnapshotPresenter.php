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
 * - **`unavailable`** — a value carrying `sw0:` ciphertext, masked as defense in
 *   depth even though memory is not sealed at rest. This covers both a top-level
 *   scalar with the sealed prefix AND a structured (array) value hiding a nested
 *   sealed string, which would otherwise json-encode past a top-level prefix
 *   check — the presenter never renders a value containing `sw0:` ciphertext;
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
     * Core's persisted-cipher sentinel prefix. Inlined as a literal (matching
     * {@see DisplayField}) rather than importing the `@internal`
     * SwarmPersistenceCipher — a companion never couples to core internals
     * (records 629/632).
     */
    private const SEALED_PREFIX = 'sw0:';

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
                'scope' => self::scalarOrDash($entry['scope'] ?? null),
                'scope_id' => self::scalarOrDash($entry['scope_id'] ?? null),
                'key' => self::scalarOrDash($entry['key'] ?? null),
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
                'name' => self::scalarOrDash($call['name'] ?? null),
                'arguments' => self::renderValue($call['arguments'] ?? null),
                'result' => self::renderValue($call['result'] ?? null),
            ];
        }

        return $mapped;
    }

    /**
     * Map a raw memory/tool value to its display string across the four states.
     * Ciphertext never escapes: a `sw0:` value is flagged unavailable, whether
     * it is a top-level scalar (masked by {@see DisplayField}) or a nested string
     * inside a structured value (caught by {@see containsSealed()}).
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

        // A structured value is displayed as JSON, but DisplayField only checks
        // the sw0: prefix on the top-level serialized string — a nested sealed
        // string (e.g. ['k' => 'sw0:...']) would json-encode to {"k":"sw0:..."}
        // and slip through. Scan the raw structure recursively first and degrade
        // the WHOLE value rather than leak an inner ciphertext.
        if (is_array($raw) && self::containsSealed($raw)) {
            return self::UNAVAILABLE;
        }

        // Route through DisplayField for the top-level sw0: mask + stringify:
        // a scalar value that still looks like ciphertext is masked, never leaked.
        $field = DisplayField::fromRow(['value' => $raw], 'value');

        return $field->isAvailable() ? (string) $field->value : self::UNAVAILABLE;
    }

    /**
     * Recursively report whether any string anywhere in a structured value
     * carries the sealed prefix — the guard against a nested ciphertext leaking
     * when the value is rendered as JSON.
     *
     * @param  array<array-key, mixed>  $value
     */
    private static function containsSealed(array $value): bool
    {
        foreach ($value as $item) {
            if (is_string($item) && str_starts_with($item, self::SEALED_PREFIX)) {
                return true;
            }

            if (is_array($item) && self::containsSealed($item)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A scope/scope_id/key/name cell: the scalar string, or a dash placeholder
     * for an absent/non-scalar addressing field.
     *
     * Uses `—` rather than the value-path `none`/`unavailable` vocabulary on
     * purpose: these are structural ADDRESSING fields (scope, scope_id, key,
     * tool name), always present in a well-formed snapshot, so a blank one is a
     * malformed-row artifact — not a captured-but-absent value the operator
     * should reason about. Keeping the vocabularies distinct stops a stray dash
     * from reading as a meaningful "no value" state.
     */
    private static function scalarOrDash(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '—';
    }
}
