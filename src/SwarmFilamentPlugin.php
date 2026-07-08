<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarmFilament;

use Filament\Contracts\Plugin;
use Filament\Panel;

/**
 * The Filament plugin. Add it to a panel to mount Swarm's read-only
 * observability surfaces:
 *
 * ```php
 * use BuiltByBerry\LaravelSwarmFilament\SwarmFilamentPlugin;
 *
 * public function panel(Panel $panel): Panel
 * {
 *     return $panel->plugin(SwarmFilamentPlugin::make());
 * }
 * ```
 *
 * Access to these surfaces is authorization-agnostic at the package level, in
 * keeping with the core read contracts — gate visibility in the host app
 * (a Filament access policy / `canAccessPanel`, or a resource authorization
 * gate). The surfaces are strictly view-only; operator control verbs
 * (pause/resume/cancel/signal) live in the separate paid operator console.
 */
class SwarmFilamentPlugin implements Plugin
{
    public function getId(): string
    {
        return 'laravel-swarm';
    }

    public static function make(): static
    {
        return app(static::class);
    }

    public static function get(): static
    {
        /** @var static $plugin */
        $plugin = filament(static::class);

        return $plugin;
    }

    public function register(Panel $panel): void
    {
        // Read-only observability resources are registered here as they land:
        // runs, steps, durable state, memory, streaming, and audit health.
        $panel->resources([
            // Resources\SwarmRunResource::class,
        ]);
    }

    public function boot(Panel $panel): void
    {
        //
    }
}
