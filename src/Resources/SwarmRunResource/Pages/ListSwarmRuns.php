<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarmFilament\Resources\SwarmRunResource\Pages;

use BuiltByBerry\LaravelSwarmFilament\Resources\SwarmRunResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;

/**
 * The runs index. Read-only: no create or other header actions.
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
}
