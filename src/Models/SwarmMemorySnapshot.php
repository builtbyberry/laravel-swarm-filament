<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarmFilament\Models;

use BuiltByBerry\LaravelSwarm\Contracts\SnapshotsMemory;
use BuiltByBerry\LaravelSwarmFilament\Support\MemorySnapshotPresenter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Read-only Eloquent model over Swarm's `swarm_memory_snapshots` table, exposing
 * ONLY the plaintext index columns so a Filament table can filter, sort, and
 * paginate natively (which the array-returning
 * {@see SnapshotsMemory::allForRun()} cannot drive).
 *
 * Two guarantees make this safe as a companion read surface:
 *
 * 1. **Index columns only.** A global scope restricts every query to the
 *    {@see DISPLAY_COLUMNS} whitelist — `(id, run_id, step_index, timestamps)` —
 *    so the value-bearing JSON columns (`payload`, which holds the frozen
 *    agent-visible memory entries, and `tool_calls`) are never even selected.
 *    A raw memory value can therefore never reach a table cell, and a Filament
 *    column bound to `$snapshot->payload` resolves to `null` (unloaded), never
 *    the frozen entries. The frozen entries are shown in the DETAIL view through
 *    the public {@see SnapshotsMemory::find()} contract, mapped by
 *    {@see MemorySnapshotPresenter}
 *    (value / redacted / unavailable / none) — never this model.
 * 2. **Read-only intent.** The companion only ever reads; as a safety net an
 *    accidental model write (`save()`/`delete()`) fails loud. (This guards the
 *    model-instance path the companion actually uses; a raw query-builder write
 *    would bypass model events, but the companion never issues one.)
 *
 * The persisted snapshot is the read-time realization of the propagation-policy
 * filtered agent-visible memory view: every runner freezes exactly what the
 * swarm's `MemoryPropagationPolicy` presented, so browsing snapshots respects
 * that policy structurally — keys the policy hides never entered the row.
 *
 * @property-read int $id
 * @property-read string $run_id
 * @property-read int $step_index
 * @property-read Carbon|null $created_at
 * @property-read Carbon|null $updated_at
 *
 * @internal
 */
class SwarmMemorySnapshot extends Model
{
    public $timestamps = false;

    /**
     * The display-safe index columns — the ONLY columns this model selects.
     * Deliberately excludes the value-bearing JSON columns (`payload`,
     * `tool_calls`), which are read in the detail view through the
     * {@see SnapshotsMemory} contract, never off this model.
     *
     * @var list<string>
     */
    public const DISPLAY_COLUMNS = [
        'id',
        'run_id',
        'step_index',
        'created_at',
        'updated_at',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'step_index' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function getTable(): string
    {
        return (string) config('swarm.tables.memory_snapshots', 'swarm_memory_snapshots');
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

        // Read-only: any write against core's snapshot table fails loud.
        static::saving(static fn (): never => throw new LogicException('SwarmMemorySnapshot is a read-only view of Swarm memory snapshots and cannot be written.'));
        static::deleting(static fn (): never => throw new LogicException('SwarmMemorySnapshot is a read-only view of Swarm memory snapshots and cannot be deleted.'));
    }
}
