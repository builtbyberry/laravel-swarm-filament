<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarmFilament\Resources;

use BuiltByBerry\LaravelSwarmFilament\Concerns\ResolvesSwarmNavigation;
use BuiltByBerry\LaravelSwarmFilament\Support\SwarmObservabilityGate;
use Filament\Resources\Resource;
use Illuminate\Database\Eloquent\Model;

/**
 * Base class for the read-only observability resources.
 *
 * Under the run-centric IA the concrete resource is the runs explorer
 * ({@see SwarmRunResource}); durable execution, memory, streaming, and audit are
 * folded into a run as facets rather than standalone resources. This stays an
 * abstract base so the shared seam holds if further resources are ever added.
 *
 * Applies the deny-by-default {@see SwarmObservabilityGate} decision directly in
 * its Filament authorization hooks so every observability resource — its
 * navigation entry, its list, and its record views — is authorized uniformly
 * against the configured `viewSwarmObservability` ability, and
 * {@see ResolvesSwarmNavigation} so the navigation placement is config-driven from
 * one place — concrete resources extend this rather than wiring either
 * individually, so the authorization and navigation surfaces can never drift
 * resource-to-resource. Pages and widgets apply the same gate in their own hooks,
 * whose Filament authorization signatures differ from a Resource's.
 */
abstract class SwarmResource extends Resource
{
    use ResolvesSwarmNavigation;

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

    /**
     * The short label for a run's swarm class — the class basename, so an index
     * column shows `Example` not `App\Swarms\Example` (the full FQCN stays as the
     * cell tooltip). Shared by every observability resource so the transform is
     * defined once and testable directly.
     */
    public static function swarmLabel(?string $swarmClass): string
    {
        return $swarmClass === null ? '' : class_basename($swarmClass);
    }
}
