<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarmFilament;

use Illuminate\Support\ServiceProvider;

/**
 * Auto-discovered package provider. The observability surfaces are registered
 * into a host Filament panel through {@see SwarmFilamentPlugin} (added to the
 * panel via `->plugin(SwarmFilamentPlugin::make())`), not here — this provider
 * only wires package-level assets (views, translations, publishable config).
 *
 * The panel resources read persisted swarm data exclusively through the public
 * read-only contracts shipped in laravel-swarm v0.19 — `InspectsDurableRuns`,
 * `ReadableRunHistoryStore`, and `ReadableAuditOutbox` — which display-decrypt
 * per row (honoring `swarm.persistence.decrypt_failure_policy`) and never mutate
 * state. This package never touches the `@internal` `SwarmPersistenceCipher` or
 * the strict-operational reads.
 */
class SwarmFilamentServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'swarm-filament');
    }
}
