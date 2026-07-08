<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarmFilament\Resources\SwarmRunResource\Pages;

use BuiltByBerry\LaravelSwarm\Contracts\ReadableRunHistoryStore;
use BuiltByBerry\LaravelSwarmFilament\Models\SwarmRun;
use BuiltByBerry\LaravelSwarmFilament\Resources\SwarmRunResource;
use BuiltByBerry\LaravelSwarmFilament\Support\RunDisplayPresenter;
use BuiltByBerry\LaravelSwarmFilament\Support\RunGraph;
use BuiltByBerry\LaravelSwarmFilament\Support\WorkflowGraphPresenter;
use Filament\Actions\Action;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * The per-run detail view: run summary, context, output, and a step timeline.
 *
 * The routed record is the plaintext {@see SwarmRun} model (index columns only).
 * Sealed payloads are resolved separately, once, through the
 * {@see ReadableRunHistoryStore::findForDisplay()} display contract and mapped by
 * {@see RunDisplayPresenter} — so this page never reads a sealed column off the
 * model and never leaks `sw0:` ciphertext.
 */
final class ViewSwarmRun extends ViewRecord
{
    protected static string $resource = SwarmRunResource::class;

    /**
     * The presented (display-decrypted, degrade-safe) record, memoized so the
     * store is read once per request regardless of how many entries render it.
     *
     * @var array<string, mixed>|null
     */
    private ?array $presented = null;

    /**
     * @return array<string, mixed>
     */
    private function presented(): array
    {
        return $this->presented ??= self::resolveDisplay(
            app(ReadableRunHistoryStore::class),
            (string) $this->getRecord()->getKey(),
        );
    }

    /**
     * Resolve a run's display record through the contract and map it — or throw
     * {@see ModelNotFoundException} when the display record is gone, so a
     * purged/expired sealed row 404s rather than rendering an empty shell.
     *
     * Extracted as a static so the null-guard is testable directly, below the
     * Livewire render layer.
     *
     * @return array<string, mixed>
     */
    public static function resolveDisplay(ReadableRunHistoryStore $store, string $runId): array
    {
        $display = $store->findForDisplay($runId);

        if ($display === null) {
            throw (new ModelNotFoundException)->setModel(SwarmRun::class, [$runId]);
        }

        return RunDisplayPresenter::present($display);
    }

    public function infolist(Schema $schema): Schema
    {
        $data = $this->presented();

        $graph = RunGraph::fromRun($data);

        return $schema->components([
            Section::make('Workflow')
                ->schema([
                    ViewEntry::make('workflow')
                        ->hiddenLabel()
                        ->view('swarm-filament::graph')
                        ->state(WorkflowGraphPresenter::present($graph['nodes'], $graph['edges'])),
                ]),
            Section::make('Run')
                ->columns(2)
                ->schema([
                    TextEntry::make('run_id')->label('Run')->state($data['run_id'])->fontFamily('mono'),
                    TextEntry::make('swarm_class')->label('Swarm')->state($data['swarm_class']),
                    TextEntry::make('topology')->badge()->state($data['topology']),
                    TextEntry::make('status')
                        ->badge()
                        ->color(SwarmRunResource::statusColor(is_string($data['status']) ? $data['status'] : null))
                        ->state($data['status']),
                    TextEntry::make('started_at')->label('Started')->dateTime()->state($data['started_at']),
                    TextEntry::make('finished_at')->label('Finished')->dateTime()->placeholder('—')->state($data['finished_at']),
                ]),
            Section::make('Context')->schema([
                TextEntry::make('context')->hiddenLabel()->state($data['context']),
            ]),
            Section::make('Output')->schema([
                TextEntry::make('output')->hiddenLabel()->state($data['output']),
            ]),
            Section::make('Steps')->schema([
                RepeatableEntry::make('steps')
                    ->hiddenLabel()
                    ->state($data['steps'])
                    ->placeholder('No steps recorded.')
                    ->schema([
                        TextEntry::make('step_index')->label('#'),
                        TextEntry::make('agent_class')->label('Agent'),
                        TextEntry::make('input'),
                        TextEntry::make('output'),
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
