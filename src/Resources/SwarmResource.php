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
}
