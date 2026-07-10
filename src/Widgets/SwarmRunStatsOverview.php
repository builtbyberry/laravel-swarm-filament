<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarmFilament\Widgets;

use BuiltByBerry\LaravelSwarmFilament\Resources\SwarmRunResource\Pages\ListSwarmRuns;
use BuiltByBerry\LaravelSwarmFilament\Support\RunStatsPresenter;
use BuiltByBerry\LaravelSwarmFilament\Support\SwarmObservabilityGate;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * The runs-index headline strip: total runs, how many need attention, recent p50
 * latency, and recent token throughput — the insight-first landing the run-centric
 * design leads with, so the index opens with "what's happening" rather than a bare
 * table. Registered as a header widget on {@see ListSwarmRuns}.
 *
 * Reads only the plaintext DISPLAY_COLUMNS via {@see RunStatsPresenter} (never a
 * sealed column), and never polls — it recomputes on page load, not on a timer.
 *
 * Deny-by-default authorized. It mirrors {@see SwarmWidget::canView()} rather than
 * extending that base because a Filament stats widget must extend
 * {@see StatsOverviewWidget} (single inheritance) — but the authorization DECISION
 * still lives once in {@see SwarmObservabilityGate}, so the two widget surfaces
 * cannot drift apart.
 */
final class SwarmRunStatsOverview extends StatsOverviewWidget
{
    // Render inline with the index page rather than deferring — the strip is the
    // landing page's headline, so a lazy placeholder would flash empty on first paint.
    protected static bool $isLazy = false;

    protected ?string $pollingInterval = null;

    public static function canView(): bool
    {
        return SwarmObservabilityGate::allows();
    }

    /**
     * @return array<int, Stat>
     */
    protected function getStats(): array
    {
        $stats = RunStatsPresenter::present();
        $recent = 'over last '.number_format($stats['window']).' runs';
        $needsAttention = $stats['needs_attention'];

        return [
            Stat::make('Runs', number_format($stats['total']))
                ->description('all time')
                ->color('gray'),
            Stat::make('Needs attention', number_format($needsAttention))
                ->description($needsAttention > 0 ? 'failed runs' : 'all clear')
                ->color($needsAttention > 0 ? 'danger' : 'success'),
            Stat::make('p50 latency', $stats['p50_latency'] ?? '—')
                ->description($recent)
                ->color('gray'),
            Stat::make('Tokens', number_format($stats['tokens']))
                ->description($recent)
                ->color('gray'),
        ];
    }
}
