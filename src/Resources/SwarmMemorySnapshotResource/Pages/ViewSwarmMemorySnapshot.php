<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarmFilament\Resources\SwarmMemorySnapshotResource\Pages;

use BuiltByBerry\LaravelSwarm\Contracts\SnapshotsMemory;
use BuiltByBerry\LaravelSwarmFilament\Models\SwarmMemorySnapshot;
use BuiltByBerry\LaravelSwarmFilament\Resources\SwarmMemorySnapshotResource;
use BuiltByBerry\LaravelSwarmFilament\Support\MemorySnapshotPresenter;
use Filament\Actions\Action;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * The per-snapshot detail view: snapshot summary, the frozen agent-visible
 * memory entries, and the tool-call timeline.
 *
 * The routed record is the plaintext {@see SwarmMemorySnapshot} model (index
 * columns only). The frozen payload is resolved separately, once, through the
 * {@see SnapshotsMemory::find()} contract keyed by the record's
 * `(run_id, step_index)` and mapped by {@see MemorySnapshotPresenter} — so this
 * page never reads the `payload` / `tool_calls` JSON off the model, renders a
 * redacted value as a clear `[redacted]`, and never leaks `sw0:` ciphertext.
 */
final class ViewSwarmMemorySnapshot extends ViewRecord
{
    protected static string $resource = SwarmMemorySnapshotResource::class;

    /**
     * The presented (leak-safe, degrade-safe) snapshot, memoized so the store is
     * read once per request regardless of how many entries render it.
     *
     * @var array<string, mixed>|null
     */
    private ?array $presented = null;

    /**
     * @return array<string, mixed>
     */
    private function presented(): array
    {
        $record = $this->getRecord();

        return $this->presented ??= self::resolveDisplay(
            app(SnapshotsMemory::class),
            (string) $record->getAttribute('run_id'),
            (int) $record->getAttribute('step_index'),
        );
    }

    /**
     * Resolve a snapshot through the contract and map it — or throw
     * {@see ModelNotFoundException} when the snapshot is gone (e.g. the run and
     * its cascaded snapshots were purged, or persistence is in cache mode where
     * the no-op store returns null), so a missing snapshot 404s rather than
     * rendering an empty shell.
     *
     * Extracted as a static so the null-guard is testable directly, below the
     * Livewire render layer.
     *
     * @return array<string, mixed>
     */
    public static function resolveDisplay(SnapshotsMemory $snapshots, string $runId, int $stepIndex): array
    {
        $snapshot = $snapshots->find($runId, $stepIndex);

        if ($snapshot === null) {
            throw (new ModelNotFoundException)->setModel(SwarmMemorySnapshot::class, ["{$runId}:{$stepIndex}"]);
        }

        return MemorySnapshotPresenter::present($snapshot);
    }

    public function infolist(Schema $schema): Schema
    {
        $data = $this->presented();

        return $schema->components([
            Section::make('Snapshot')
                ->columns(2)
                ->schema([
                    TextEntry::make('run_id')->label('Run')->state($data['run_id'])->fontFamily('mono'),
                    TextEntry::make('step_index')->label('Step')->state($data['step_index']),
                    TextEntry::make('recorded_at')->label('Recorded')->dateTime()->placeholder('—')->state($data['recorded_at']),
                    TextEntry::make('updated_at')->label('Updated')->dateTime()->placeholder('—')->state($data['updated_at']),
                    TextEntry::make('entry_count')->label('Entries')->state($data['entry_count']),
                    TextEntry::make('tool_call_count')->label('Tool calls')->state($data['tool_call_count']),
                ]),
            Section::make('Memory entries')
                ->description('The agent-visible memory view frozen at this step — already filtered by the swarm\'s propagation policy. Redacted values show as [redacted].')
                ->schema([
                    RepeatableEntry::make('entries')
                        ->hiddenLabel()
                        ->state($data['entries'])
                        ->placeholder('No memory entries captured at this step.')
                        ->schema([
                            TextEntry::make('scope')->badge(),
                            TextEntry::make('scope_id')->label('Scope ID')->fontFamily('mono'),
                            TextEntry::make('key'),
                            TextEntry::make('value'),
                        ]),
                ]),
            Section::make('Tool calls')->schema([
                RepeatableEntry::make('tool_calls')
                    ->hiddenLabel()
                    ->state($data['tool_calls'])
                    ->placeholder('No tool calls recorded for this invocation.')
                    ->schema([
                        TextEntry::make('name'),
                        TextEntry::make('arguments'),
                        TextEntry::make('result'),
                    ]),
            ]),
        ]);
    }

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
