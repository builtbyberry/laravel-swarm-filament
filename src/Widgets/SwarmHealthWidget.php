<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarmFilament\Widgets;

use BuiltByBerry\LaravelSwarmFilament\Concerns\ProvidesSwarmHealthReport;
use BuiltByBerry\LaravelSwarmFilament\Pages\SwarmHealthPage;
use BuiltByBerry\LaravelSwarmFilament\Support\SwarmHealthReport;

/**
 * A compact dashboard widget mirroring the {@see SwarmHealthPage}:
 * the `swarm:health` durable + audit readiness verdict as an at-a-glance
 * pass/fail/degraded panel.
 *
 * Read-only and payload-free — it renders the same {@see SwarmHealthReport} the
 * page does, sourced through the shared {@see ProvidesSwarmHealthReport} trait.
 * Access is deny-by-default through {@see SwarmWidget::canView()}.
 */
final class SwarmHealthWidget extends SwarmWidget
{
    use ProvidesSwarmHealthReport;

    /**
     * @var view-string
     */
    protected string $view = 'swarm-filament::widgets.swarm-health';

    protected int|string|array $columnSpan = 'full';
}
