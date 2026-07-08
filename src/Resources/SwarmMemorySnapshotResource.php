<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarmFilament\Resources;

use BackedEnum;
use BuiltByBerry\LaravelSwarmFilament\Models\SwarmMemorySnapshot;
use BuiltByBerry\LaravelSwarmFilament\Resources\SwarmMemorySnapshotResource\Pages;
use BuiltByBerry\LaravelSwarmFilament\Support\MemorySnapshotPresenter;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\PageRegistration;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * The memory viewer — a read-only index of frozen {@see SwarmMemorySnapshot}
 * rows with a per-snapshot detail view.
 *
 * The INDEX table binds the {@see SwarmMemorySnapshot} model, which selects only
 * the plaintext index columns, so filtering / sorting / pagination run natively
 * in the database and no memory value is ever projected into a table cell. The
 * per-snapshot DETAIL view ({@see Pages\ViewSwarmMemorySnapshot}) sources the
 * frozen agent-visible entries and tool calls through the public
 * `SnapshotsMemory::find()` contract — never this model — mapped by
 * {@see MemorySnapshotPresenter} so a
 * redacted value renders as a clear `[redacted]` and a still-`sw0:` value degrades
 * to a placeholder rather than leaking.
 *
 * A snapshot is the read-time realization of the propagation-policy-filtered
 * agent-visible memory view: every runner froze exactly what the swarm's
 * `MemoryPropagationPolicy` presented, so this surface respects that policy
 * structurally without re-running the (`@internal`) view builder at read time.
 *
 * Strictly read-only: the resource registers only index + view pages (no create /
 * edit / delete routes), and inherits the deny-by-default authorization gate from
 * {@see SwarmResource}.
 */
final class SwarmMemorySnapshotResource extends SwarmResource
{
    protected static ?string $model = SwarmMemorySnapshot::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-cpu-chip';

    public static function getModelLabel(): string
    {
        return 'Memory snapshot';
    }

    public static function getPluralModelLabel(): string
    {
        return 'Memory snapshots';
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
                TextColumn::make('step_index')
                    ->label('Step')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label('Recorded')
                    ->dateTime()
                    ->placeholder('—')
                    ->sortable(),
                TextColumn::make('updated_at')
                    ->label('Updated')
                    ->dateTime()
                    ->placeholder('—')
                    ->sortable(),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->recordUrl(static fn (SwarmMemorySnapshot $record): string => Pages\ViewSwarmMemorySnapshot::getUrl(['record' => $record->getKey()]))
            ->defaultSort('created_at', 'desc');
    }

    /**
     * @return array<string, PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSwarmMemorySnapshots::route('/'),
            'view' => Pages\ViewSwarmMemorySnapshot::route('/{record}'),
        ];
    }
}
