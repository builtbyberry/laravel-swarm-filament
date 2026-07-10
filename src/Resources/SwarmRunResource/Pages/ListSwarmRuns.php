<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarmFilament\Resources\SwarmRunResource\Pages;

use BuiltByBerry\LaravelSwarmFilament\Resources\SwarmRunResource;
use BuiltByBerry\LaravelSwarmFilament\Widgets\SwarmRunStatsOverview;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Filament\Widgets\Widget;
use Filament\Widgets\WidgetConfiguration;

/**
 * The runs index. Read-only: no create or other header actions. Leads with the
 * aggregate stats strip so the landing page opens with insight, not a bare table.
 */
final class ListSwarmRuns extends ListRecords
{
    protected static string $resource = SwarmRunResource::class;

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [];
    }

    /**
     * @return array<int, class-string<Widget> | WidgetConfiguration>
     */
    protected function getHeaderWidgets(): array
    {
        return [
            SwarmRunStatsOverview::class,
        ];
    }
}
