<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarmFilament\Concerns;

use BuiltByBerry\LaravelSwarmFilament\Support\SwarmObservabilityGate;
use Illuminate\Database\Eloquent\Model;

/**
 * Deny-by-default authorization for a read-only observability **Resource**.
 *
 * Overrides Filament's Resource authorization hooks so the resource — its
 * navigation entry, its list, and its record views — is visible only when
 * {@see SwarmObservabilityGate::allows()} grants the configured ability. Every
 * observability resource applies this trait.
 *
 * Pages and widgets call {@see SwarmObservabilityGate::allows()} directly in their
 * own `canAccess()` / `canView()` — their Filament authorization signatures differ
 * from a Resource's, so they can't share this trait.
 */
trait AuthorizesSwarmObservability
{
    public static function canAccess(): bool
    {
        return SwarmObservabilityGate::allows();
    }

    public static function canViewAny(): bool
    {
        return SwarmObservabilityGate::allows();
    }

    public static function canView(Model $record): bool
    {
        return SwarmObservabilityGate::allows();
    }
}
