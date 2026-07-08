<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarmFilament\Resources;

use BackedEnum;
use BuiltByBerry\LaravelSwarmFilament\Models\SwarmRun;
use BuiltByBerry\LaravelSwarmFilament\Pages\ViewSwarmStream;
use BuiltByBerry\LaravelSwarmFilament\Resources\SwarmRunResource\Pages;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\PageRegistration;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * The runs explorer — a read-only index of Swarm runs with a per-run detail view.
 *
 * The INDEX table binds the {@see SwarmRun} model, which selects only the
 * plaintext display columns, so filtering / sorting / pagination run natively in
 * the database and no sealed column is ever projected into a table cell. The
 * per-run DETAIL view ({@see Pages\ViewSwarmRun}) sources every sealed field
 * through the `ReadableRunHistoryStore::findForDisplay()` display contract — never
 * this model — so the run context, run output, and per-step IO are display-
 * decrypted per field and degrade to a placeholder rather than leaking `sw0:`.
 *
 * Strictly read-only: the resource registers only index + view pages (no create /
 * edit / delete routes), and inherits the deny-by-default authorization gate from
 * {@see SwarmResource}.
 */
final class SwarmRunResource extends SwarmResource
{
    protected static ?string $model = SwarmRun::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-rectangle-stack';

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
        return 'Swarm run';
    }

    public static function getPluralModelLabel(): string
    {
        return 'Swarm runs';
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
                    ->tooltip(static fn (SwarmRun $record): string => $record->swarm_class)
                    ->searchable()
                    ->sortable(),
                TextColumn::make('topology')
                    ->badge()
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
                    'running' => 'Running',
                    'completed' => 'Completed',
                    'failed' => 'Failed',
                ]),
            ])
            ->recordActions([
                ViewAction::make(),
                Action::make('stream')
                    ->label('Timeline')
                    ->icon('heroicon-o-bars-arrow-down')
                    ->url(static fn (SwarmRun $record): string => ViewSwarmStream::getUrl(['record' => $record->getKey()])),
            ])
            ->recordUrl(static fn (SwarmRun $record): string => Pages\ViewSwarmRun::getUrl(['record' => $record->getKey()]))
            ->defaultSort('created_at', 'desc');
    }

    /**
     * @return array<string, PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSwarmRuns::route('/'),
            'view' => Pages\ViewSwarmRun::route('/{record}'),
        ];
    }

    /**
     * The short label for a run's swarm class — the class basename, so the index
     * column shows `Example` not `App\Swarms\Example` (the full FQCN stays as the
     * cell tooltip). Extracted so the transform is testable.
     */
    public static function swarmLabel(?string $swarmClass): string
    {
        return $swarmClass === null ? '' : class_basename($swarmClass);
    }

    /**
     * A Filament color token for a run status. Shared by the index badge and the
     * detail-view status entry so the two never diverge.
     */
    public static function statusColor(?string $status): string
    {
        return match ($status) {
            'completed' => 'success',
            'failed' => 'danger',
            'running' => 'warning',
            default => 'gray',
        };
    }
}
