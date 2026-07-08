<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarmFilament\Pages;

use BackedEnum;
use BuiltByBerry\LaravelSwarm\Contracts\InspectsDurableRuns;
use BuiltByBerry\LaravelSwarm\Contracts\ReadableAuditOutbox;
use BuiltByBerry\LaravelSwarmFilament\Support\SwarmHealthReport;

/**
 * The read-only health dashboard: the `swarm:health` readiness signal (durable +
 * audit persistence reachability) surfaced as a pass/fail/degraded view.
 *
 * Strictly view-only — it renders the {@see SwarmHealthReport} verdict and nothing
 * else: no run payloads, no counts beyond the non-sensitive summaries the report
 * exposes, and no control verbs (pause/resume/reconcile live in the paid operator
 * console). Access is deny-by-default through {@see SwarmPage::canAccess()}.
 *
 * The report is sourced only from the public read seams — {@see InspectsDurableRuns}
 * and {@see ReadableAuditOutbox} — resolved from the container and handed to the
 * pure evaluator, which swallows any probe failure into a degraded state so the
 * page never 500s.
 */
final class SwarmHealthPage extends SwarmPage
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-heart';

    protected static ?string $title = 'Swarm health';

    protected static ?string $navigationLabel = 'Health';

    protected static ?string $slug = 'swarm-health';

    protected string $view = 'swarm-filament::pages.swarm-health';

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
