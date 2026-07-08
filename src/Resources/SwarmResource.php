<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarmFilament\Resources;

use BuiltByBerry\LaravelSwarmFilament\Concerns\AuthorizesSwarmObservability;
use Filament\Resources\Resource;

/**
 * Base class for the read-only observability resources (runs, durable inspector,
 * memory, streaming, audit).
 *
 * Applies the deny-by-default {@see AuthorizesSwarmObservability} gate so every
 * observability resource is authorized uniformly against the configured
 * `viewSwarmObservability` ability — concrete resources extend this rather than
 * wiring the gate individually, so the authorization surface can never drift
 * resource-to-resource.
 */
abstract class SwarmResource extends Resource
{
    use AuthorizesSwarmObservability;

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
