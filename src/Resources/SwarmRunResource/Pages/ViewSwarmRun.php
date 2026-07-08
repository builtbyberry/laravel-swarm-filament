<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarmFilament\Resources\SwarmRunResource\Pages;

use BuiltByBerry\LaravelSwarm\Contracts\ReadableRunHistoryStore;
use BuiltByBerry\LaravelSwarmFilament\Models\SwarmRun;
use BuiltByBerry\LaravelSwarmFilament\Resources\SwarmRunResource;
use BuiltByBerry\LaravelSwarmFilament\Support\RunDisplayPresenter;
use Filament\Actions\Action;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
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
        if ($this->presented !== null) {
            return $this->presented;
        }

        $runId = (string) $this->getRecord()->getKey();
        $display = app(ReadableRunHistoryStore::class)->findForDisplay($runId);

        // The plaintext row resolved (or we would not be here), but its display
        // record is gone — treat as not found rather than render an empty shell.
        if ($display === null) {
            throw (new ModelNotFoundException)->setModel(SwarmRun::class, [$runId]);
        }

        return $this->presented = RunDisplayPresenter::present($display);
    }

    public function infolist(Schema $schema): Schema
    {
        $data = $this->presented();

        return $schema->components([
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
