<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarmFilament\Support;

use BuiltByBerry\LaravelSwarmFilament\Concerns\AuthorizesSwarmObservability;
use Illuminate\Support\Facades\Gate;

/**
 * The single, deny-by-default access decision for every read-only observability
 * surface (resources, pages, widgets).
 *
 * Access is granted only when the host application's Gate allows the configured
 * ability — `config('swarm-filament.authorization.ability')`, default
 * `viewSwarmObservability`. With no Gate defined for that ability, `Gate::allows`
 * returns false, so the surfaces stay hidden until the operator explicitly grants
 * access. Setting the config ability to `null` (or an empty string) defers
 * entirely to Filament's own panel / resource authorization instead.
 *
 * Resources apply {@see AuthorizesSwarmObservability},
 * which delegates here; pages and widgets (whose Filament authorization hooks have
 * different signatures) call {@see allows()} directly from their own `canAccess()`
 * / `canView()`.
 */
final class SwarmObservabilityGate
{
    public static function allows(): bool
    {
        $ability = config('swarm-filament.authorization.ability');

        if (! is_string($ability) || $ability === '') {
            // Deferred — let Filament's own authorization decide.
            return true;
        }

        return Gate::allows($ability);
    }
}
