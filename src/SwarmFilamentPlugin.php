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
        // All read-only observability surfaces. Resources back the run-scoped
        // explorers (runs, durable state, memory snapshots); the rest are
        // standalone pages/widgets. Every surface gates itself deny-by-default —
        // resources via SwarmResource, pages/widgets via SwarmPage/SwarmWidget.
        $panel
            ->resources([
                Resources\SwarmRunResource::class,
                Resources\SwarmDurableRunResource::class,
                Resources\SwarmMemorySnapshotResource::class,
            ])
            ->pages([
                // Per-run streaming / causal-log viewer (keyed by run id, reached
                // from the runs explorer).
                Pages\ViewSwarmStream::class,
                // Health dashboard.
                Pages\SwarmHealthPage::class,
                // Audit surfaces: outbox health (non-consuming index + a
                // payload-minimized single-row detail) and the per-run audit trace.
                Pages\AuditOutboxHealth::class,
                Pages\AuditOutboxRecord::class,
                Pages\AuditTrace::class,
            ])
            ->widgets([
                Widgets\SwarmHealthWidget::class,
            ]);
    }

    public function boot(Panel $panel): void
    {
        //
    }
}
