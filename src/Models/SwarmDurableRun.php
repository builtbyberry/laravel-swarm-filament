<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarmFilament\Models;

use BuiltByBerry\LaravelSwarm\Contracts\InspectsDurableRuns;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Read-only Eloquent model over Swarm's durable-runs table, exposing ONLY the
 * plaintext columns so a Filament table can filter, sort, and paginate the
 * durable-run index natively (which the array-returning `InspectsDurableRuns`
 * seam cannot drive). The per-run DETAIL — parallel branches, child runs,
 * hierarchical node outputs, waits, signals, progress, history — carries the
 * sealed payloads and is assembled ONLY through {@see InspectsDurableRuns}
 * (display-decrypted per row), never this model.
 *
 * Unlike the run-history table, the `swarm_durable_runs` table itself holds NO
 * sealed columns — every sealed field lives in a side table (node outputs,
 * branch IO, child context). Even so this model mirrors {@see SwarmRun}'s
 * discipline exactly:
 *
 * 1. **Plaintext-only.** A global scope restricts every query to the
 *    {@see DISPLAY_COLUMNS} whitelist, so the operational columns
 *    (`execution_token`, `leased_until`) are never selected and a Filament
 *    column bound to one resolves to `null` (unloaded), never a live token.
 * 2. **Read-only intent.** An accidental model write (`save()`/`delete()`)
 *    fails loud. This guards the model-instance path the companion actually
 *    uses; a raw query-builder write would bypass model events, but the
 *    companion never issues one.
 *
 * @property-read string $run_id
 * @property-read string $swarm_class
 * @property-read string $topology
 * @property-read string $status
 * @property-read string|null $execution_mode
 * @property-read string|null $coordination_profile
 * @property-read Carbon|null $created_at
 * @property-read Carbon|null $finished_at
 * @property-read Carbon|null $updated_at
 *
 * @internal
 */
class SwarmDurableRun extends Model
{
    protected $primaryKey = 'run_id';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    /**
     * The display-safe plaintext columns — the ONLY columns this model selects.
     * Deliberately excludes the operational columns (`execution_token`,
     * `leased_until`) and every lifecycle/state column shown only in the detail
     * view via the inspector contract.
     *
     * @var list<string>
     */
    public const DISPLAY_COLUMNS = [
        'run_id',
        'swarm_class',
        'topology',
        'status',
        'execution_mode',
        'coordination_profile',
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
        return (string) config('swarm.tables.durable', 'swarm_durable_runs');
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

        // Read-only: any write against core's durable table fails loud.
        static::saving(static fn (): never => throw new LogicException('SwarmDurableRun is a read-only view of Swarm durable runs and cannot be written.'));
        static::deleting(static fn (): never => throw new LogicException('SwarmDurableRun is a read-only view of Swarm durable runs and cannot be deleted.'));
    }
}
