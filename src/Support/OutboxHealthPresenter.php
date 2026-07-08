<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarmFilament\Support;

use BuiltByBerry\LaravelSwarm\Contracts\ReadableAuditOutbox;

/**
 * Maps {@see ReadableAuditOutbox} reads into
 * flat, render-ready arrays for the audit outbox health page and its single-row
 * detail — the audit counterpart to the run display presenter.
 *
 * Two invariants of the outbox contract are preserved here in ONE tested place:
 *
 * - **Payload minimization.** {@see present()} maps the LIST reads
 *   (`pending()` / `deadLettered()`) to row metadata plus the display-decrypted
 *   `last_error` ONLY — it never carries the evidence `payload`, which the
 *   contract deliberately omits from the list arrays. The full payload is mapped
 *   solely by {@see presentRecord()}, from the on-demand `record($id)` read.
 * - **Ciphertext never escapes.** The sealed `last_error` field is routed through
 *   {@see DisplayField} for the same `value` / `unavailable` / `none` treatment as
 *   every other sealed member; a still-`sw0:` value degrades to a placeholder. The
 *   detail payload arrives already display-decrypted by the contract (with a
 *   `payload_available` flag): an undecryptable payload renders as `unavailable`,
 *   never the raw ciphertext, never a 500.
 *
 * Pure and container-free: it takes the already-degraded contract arrays and never
 * touches the outbox, the cipher, or Filament — so it is unit-testable directly.
 */
final class OutboxHealthPresenter
{
    private const UNAVAILABLE = 'unavailable';

    private const NONE = 'none';

    /**
     * Present the outbox health dashboard: the non-consuming summary counts plus
     * the pending and dead-letter row lists (metadata + `last_error` only).
     *
     * @param  array<string, mixed>  $summary  a `healthSummary()` result
     * @param  array<int, array<string, mixed>>  $pending  a `pending()` result
     * @param  array<int, array<string, mixed>>  $deadLettered  a `deadLettered()` result
     * @return array{available: bool, pending_count: int, dead_letter_count: int, reserved_count: int, oldest_pending_at: ?string, pending: list<array<string, mixed>>, dead_lettered: list<array<string, mixed>>}
     */
    public static function present(array $summary, array $pending, array $deadLettered): array
    {
        return [
            'available' => (bool) ($summary['available'] ?? false),
            'pending_count' => self::int($summary['pending'] ?? null),
            'dead_letter_count' => self::int($summary['dead_letter'] ?? null),
            'reserved_count' => self::int($summary['reserved'] ?? null),
            'oldest_pending_at' => self::scalar($summary['oldest_pending_at'] ?? null),
            'pending' => self::rows($pending),
            'dead_lettered' => self::rows($deadLettered),
        ];
    }

    /**
     * Present a single outbox row for the on-demand detail view, including its full
     * display-decrypted evidence `payload`. This is the ONLY presenter method that
     * carries the payload — the list mapping deliberately omits it.
     *
     * @param  array<string, mixed>  $record  a `record($id)` result
     * @return array{id: ?int, category: ?string, run_id: ?string, status: ?string, attempts: int, last_error: string, reserved_at: ?string, last_attempted_at: ?string, created_at: ?string, payload: string}
     */
    public static function presentRecord(array $record): array
    {
        return self::row($record) + [
            'payload' => self::payload($record),
        ];
    }

    /**
     * A Filament color token for an outbox row status. Lives on the presenter so the
     * health list and the single-row detail badge share one mapping and can never
     * diverge across a page refactor.
     */
    public static function statusColor(?string $status): string
    {
        return match ($status) {
            'pending' => 'warning',
            'dead_letter' => 'danger',
            default => 'gray',
        };
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private static function rows(array $rows): array
    {
        $mapped = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $mapped[] = self::row($row);
        }

        return $mapped;
    }

    /**
     * Row metadata + the display-decrypted `last_error` — and NOTHING else. There
     * is deliberately no `payload` key: the list surface is payload-minimized, and
     * this mapping is shared by both the list and the (payload-augmenting) detail.
     *
     * @param  array<string, mixed>  $row
     * @return array{id: ?int, category: ?string, run_id: ?string, status: ?string, attempts: int, last_error: string, reserved_at: ?string, last_attempted_at: ?string, created_at: ?string}
     */
    private static function row(array $row): array
    {
        return [
            'id' => self::nullableInt($row['id'] ?? null),
            'category' => self::scalar($row['category'] ?? null),
            'run_id' => self::scalar($row['run_id'] ?? null),
            'status' => self::scalar($row['status'] ?? null),
            'attempts' => self::int($row['attempts'] ?? null),
            'last_error' => self::render(DisplayField::fromRow($row, 'last_error')),
            'reserved_at' => self::scalar($row['reserved_at'] ?? null),
            'last_attempted_at' => self::scalar($row['last_attempted_at'] ?? null),
            'created_at' => self::scalar($row['created_at'] ?? null),
        ];
    }

    /**
     * Map the on-demand payload to a display string. The contract returns the
     * decrypted `payload` (array|null) paired with a `payload_available` flag:
     *
     * - `payload_available` false → `unavailable` (undecryptable, never `sw0:`);
     * - available but empty/absent → `none`;
     * - otherwise the payload pretty-printed as JSON.
     *
     * @param  array<string, mixed>  $record
     */
    private static function payload(array $record): string
    {
        if (! (bool) ($record['payload_available'] ?? false)) {
            return self::UNAVAILABLE;
        }

        $payload = $record['payload'] ?? null;

        if ($payload === null || $payload === [] || $payload === '') {
            return self::NONE;
        }

        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $json === false ? self::UNAVAILABLE : $json;
    }

    /**
     * Map a {@see DisplayField}'s three outcomes to a display string, mirroring the
     * run display presenter: a still-`sw0:` value is masked to `unavailable`.
     */
    private static function render(DisplayField $displayField): string
    {
        if ($displayField->isAvailable()) {
            return (string) $displayField->value;
        }

        return $displayField->available === false ? self::UNAVAILABLE : self::NONE;
    }

    private static function scalar(mixed $value): ?string
    {
        return is_scalar($value) ? (string) $value : null;
    }

    private static function int(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private static function nullableInt(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }
}
