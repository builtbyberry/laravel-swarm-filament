<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarmFilament\Widgets;

use BuiltByBerry\LaravelSwarm\Contracts\InspectsDurableRuns;
use BuiltByBerry\LaravelSwarm\Contracts\ReadableAuditOutbox;
use BuiltByBerry\LaravelSwarmFilament\Pages\SwarmHealthPage;
use BuiltByBerry\LaravelSwarmFilament\Support\SwarmHealthReport;

/**
 * A compact dashboard widget mirroring the {@see SwarmHealthPage}:
 * the `swarm:health` durable + audit readiness verdict as an at-a-glance
 * pass/fail/degraded panel.
 *
 * Read-only and payload-free — it renders the same {@see SwarmHealthReport} the
 * page does (sourced through the public {@see InspectsDurableRuns} /
 * {@see ReadableAuditOutbox} seams). Access is deny-by-default through
 * {@see SwarmWidget::canView()}.
 */
final class SwarmHealthWidget extends SwarmWidget
{
    /**
     * @var view-string
     */
    protected string $view = 'swarm-filament::widgets.swarm-health';

    protected int|string|array $columnSpan = 'full';

    public function report(): SwarmHealthReport
    {
        return SwarmHealthReport::for(
            app(InspectsDurableRuns::class),
            app(ReadableAuditOutbox::class),
        );
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        return [
            'report' => $this->report(),
        ];
    }
}
