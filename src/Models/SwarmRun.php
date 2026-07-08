<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarmFilament\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Read-only Eloquent model over Swarm's run-history table, exposing ONLY the
 * plaintext columns so a Filament table can filter, sort, and paginate natively
 * (which the array-returning `ReadableRunHistoryStore::query()` cannot drive).
 *
 * Two guarantees make this safe as a companion read surface:
 *
 * 1. **Plaintext-only.** A global scope restricts every query to the
 *    {@see DISPLAY_COLUMNS} whitelist, so the sealed columns (`context`,
 *    `output`, the `steps` IO) are never even selected — raw `sw0:` ciphertext
 *    can never reach a table cell, and a Filament column bound to a sealed
 *    attribute (`$run->context`) resolves to `null` (unloaded), never ciphertext.
 *    The scope governs the DEFAULT projection: an explicit `->addSelect('context')`
 *    would re-add the sealed column, but no companion query does that — sealed
 *    payloads are shown in DETAIL views through the v0.19 display contracts
 *    (`ReadableRunHistoryStore::findForDisplay`, `InspectsDurableRuns`), which
 *    display-decrypt per row — never this model.
 * 2. **Read-only intent.** The companion only ever reads; as a safety net an
 *    accidental model write (`save()`/`delete()`) fails loud. (This guards the
 *    model-instance path the companion actually uses; it is a display view, not
 *    a write-protection layer over the table — a raw query-builder write would
 *    bypass model events, but the companion never issues one.)
 *
 * @internal
 */
class SwarmRun extends Model
{
    protected $primaryKey = 'run_id';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    /**
     * The display-safe plaintext columns — the ONLY columns this model selects.
     * Deliberately excludes every sealed column (`context`, `output`, `steps`)
     * and the operational columns (`execution_token`, `leased_until`).
     *
     * @var list<string>
     */
    public const DISPLAY_COLUMNS = [
        'run_id',
        'swarm_class',
        'topology',
        'status',
        'created_at',
        'finished_at',
        'updated_at',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'created_at' => 'datetime',
        'finished_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function getTable(): string
    {
        return (string) config('swarm.tables.history', 'swarm_run_histories');
    }

    protected static function booted(): void
    {
        static::addGlobalScope('swarm-filament-display', function (Builder $query): void {
            $table = $query->getModel()->getTable();

            $query->select(array_map(
                static fn (string $column): string => "{$table}.{$column}",
                self::DISPLAY_COLUMNS,
            ));
        });

        // Read-only: any write against core's history table fails loud.
        static::saving(static fn (): never => throw new LogicException('SwarmRun is a read-only view of Swarm run history and cannot be written.'));
        static::deleting(static fn (): never => throw new LogicException('SwarmRun is a read-only view of Swarm run history and cannot be deleted.'));
    }
}
