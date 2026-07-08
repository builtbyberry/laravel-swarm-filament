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
 * Access is deny-by-default: every surface authorizes against the configured
 * `config('swarm-filament.authorization.ability')` Gate (default
 * `viewSwarmObservability`) before rendering, so absent a Gate definition in the
 * host app the surfaces stay hidden. Set that ability to `null` to defer entirely
 * to Filament's own panel / resource authorization instead. The surfaces are
 * strictly view-only; operator control verbs (pause/resume/cancel/signal) live in
 * the separate paid operator console.
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
        $plugin = filament(app(static::class)->getId());

        return $plugin;
    }

    public function register(Panel $panel): void
    {
        // Read-only observability resources are registered here as they land:
        // runs, steps, durable state, memory, streaming, and audit health.
        $panel->resources([
            Resources\SwarmRunResource::class,
            Resources\SwarmDurableRunResource::class,
            Resources\SwarmMemorySnapshotResource::class,
        ]);

        // The streaming / causal-log viewer is a per-run standalone page (keyed by
        // run id, reached from the runs explorer), so it is registered as a page,
        // not a resource. It gates itself deny-by-default in canAccess().
        $panel->pages([
            Pages\ViewSwarmStream::class,
        ]);

        // Read-only observability pages (health dashboard, …). Every Swarm page
        // extends SwarmPage, which applies the deny-by-default access gate.
        $panel->pages([
            Pages\SwarmHealthPage::class,
        ]);

        // Read-only observability widgets. Every Swarm widget extends SwarmWidget,
        // which applies the deny-by-default view gate.
        $panel->widgets([
            Widgets\SwarmHealthWidget::class,
        ]);
    }

    public function boot(Panel $panel): void
    {
        //
    }
}
