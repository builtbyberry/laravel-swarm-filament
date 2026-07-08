<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarmFilament\Resources\SwarmDurableRunResource\Pages;

use BuiltByBerry\LaravelSwarmFilament\Resources\SwarmDurableRunResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;

/**
 * The durable-runs index. Read-only: no create or other header actions.
 */
final class ListSwarmDurableRuns extends ListRecords
{
    protected static string $resource = SwarmDurableRunResource::class;

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
