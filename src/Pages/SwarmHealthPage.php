<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarmFilament\Pages;

use BackedEnum;
use BuiltByBerry\LaravelSwarmFilament\Concerns\ProvidesSwarmHealthReport;
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
 * The report is sourced through {@see ProvidesSwarmHealthReport} (shared with the
 * widget) from the public read seams, handed to the pure evaluator, which degrades
 * any probe failure so the page never 500s.
 */
final class SwarmHealthPage extends SwarmPage
{
    use ProvidesSwarmHealthReport;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-heart';

    protected static ?string $title = 'Swarm health';

    protected static ?string $navigationLabel = 'Health';

    protected static ?string $slug = 'swarm-health';

    protected string $view = 'swarm-filament::pages.swarm-health';
}
