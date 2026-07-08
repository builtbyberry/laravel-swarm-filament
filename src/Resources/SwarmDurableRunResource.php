<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarmFilament\Resources;

use BackedEnum;
use BuiltByBerry\LaravelSwarm\Contracts\InspectsDurableRuns;
use BuiltByBerry\LaravelSwarmFilament\Models\SwarmDurableRun;
use BuiltByBerry\LaravelSwarmFilament\Resources\SwarmDurableRunResource\Pages;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\PageRegistration;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * The durable-run inspector — a read-only index of durable (queued/crash-safe)
 * Swarm runs with a per-run detail view.
 *
 * The INDEX table binds the {@see SwarmDurableRun} model, which selects only the
 * plaintext durable-run columns, so filtering / sorting / pagination run
 * natively in the database. The per-run DETAIL view ({@see Pages\ViewSwarmDurableRun})
 * assembles the full durable state — parallel branches, child runs, hierarchical
 * node outputs, waits, signals, progress, and run history — through the public
 * {@see InspectsDurableRuns} contract, which
 * display-decrypts every sealed field per row and degrades to a placeholder
 * rather than leaking `sw0:` ciphertext.
 *
 * Strictly read-only: the resource registers only index + view pages (no
 * create / edit / delete routes), and inherits the deny-by-default authorization
 * gate from {@see SwarmResource}.
 */
final class SwarmDurableRunResource extends SwarmResource
{
    protected static ?string $model = SwarmDurableRun::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-cpu-chip';

    public static function getNavigationGroup(): ?string
    {
        $group = config('swarm-filament.navigation.group');

        return is_string($group) ? $group : null;
    }

    public static function getNavigationSort(): ?int
    {
        $sort = config('swarm-filament.navigation.sort');

        return is_int($sort) ? $sort : null;
    }

    public static function getModelLabel(): string
    {
        return 'Durable run';
    }

    public static function getPluralModelLabel(): string
    {
        return 'Durable runs';
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('run_id')
                    ->label('Run')
                    ->searchable()
                    ->copyable()
                    ->limit(24)
                    ->fontFamily('mono'),
                TextColumn::make('swarm_class')
                    ->label('Swarm')
                    ->formatStateUsing(static fn (?string $state): string => self::swarmLabel($state))
                    ->tooltip(static fn (SwarmDurableRun $record): string => $record->swarm_class)
                    ->searchable()
                    ->sortable(),
                TextColumn::make('topology')
                    ->badge()
                    ->sortable(),
                TextColumn::make('execution_mode')
                    ->label('Mode')
                    ->badge()
                    ->placeholder('—')
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->color(static fn (?string $state): string => self::statusColor($state))
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label('Started')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('finished_at')
                    ->label('Finished')
                    ->dateTime()
                    ->placeholder('—')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->options([
                    'pending' => 'Pending',
                    'running' => 'Running',
                    'waiting' => 'Waiting',
                    'paused' => 'Paused',
                    'completed' => 'Completed',
                    'failed' => 'Failed',
                    'cancelled' => 'Cancelled',
                    'timed_out' => 'Timed out',
                ]),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->recordUrl(static fn (SwarmDurableRun $record): string => Pages\ViewSwarmDurableRun::getUrl(['record' => $record->getKey()]))
            ->defaultSort('created_at', 'desc');
    }

    /**
     * @return array<string, PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSwarmDurableRuns::route('/'),
            'view' => Pages\ViewSwarmDurableRun::route('/{record}'),
        ];
    }

    /**
     * A Filament color token for a durable-run status. Shared by the index badge
     * and the detail-view status entry so the two never diverge.
     */
    public static function statusColor(?string $status): string
    {
        return match ($status) {
            'completed' => 'success',
            'failed', 'timed_out' => 'danger',
            'running' => 'info',
            'waiting', 'paused' => 'warning',
            default => 'gray',
        };
    }
}
