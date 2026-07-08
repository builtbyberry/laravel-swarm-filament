<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarmFilament\Resources\SwarmMemorySnapshotResource\Pages;

use BuiltByBerry\LaravelSwarmFilament\Resources\SwarmMemorySnapshotResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;

/**
 * The memory-snapshot index. Read-only: no create or other header actions.
 */
final class ListSwarmMemorySnapshots extends ListRecords
{
    protected static string $resource = SwarmMemorySnapshotResource::class;

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
