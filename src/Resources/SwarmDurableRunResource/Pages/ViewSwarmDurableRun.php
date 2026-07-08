<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarmFilament\Resources\SwarmDurableRunResource\Pages;

use BuiltByBerry\LaravelSwarm\Contracts\InspectsDurableRuns;
use BuiltByBerry\LaravelSwarmFilament\Models\SwarmDurableRun;
use BuiltByBerry\LaravelSwarmFilament\Resources\SwarmDurableRunResource;
use BuiltByBerry\LaravelSwarmFilament\Support\DurableRunPresenter;
use Filament\Actions\Action;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * The per-run durable inspector: run summary, lifecycle markers, labels /
 * details, parallel branches, child runs, hierarchical node outputs, waits,
 * signals, recorded progress, and run history.
 *
 * The routed record is the plaintext {@see SwarmDurableRun} model (index columns
 * only). The full durable detail is assembled separately, once, through the
 * {@see InspectsDurableRuns::inspect()} display contract and mapped by
 * {@see DurableRunPresenter} — so this page never reads a sealed column off the
 * model and never leaks `sw0:` ciphertext.
 */
final class ViewSwarmDurableRun extends ViewRecord
{
    protected static string $resource = SwarmDurableRunResource::class;

    /**
     * The presented (display-decrypted, degrade-safe) detail, memoized so the
     * inspector runs once per request regardless of how many entries render it.
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
            app(InspectsDurableRuns::class),
            (string) $this->getRecord()->getKey(),
        );
    }

    /**
     * Resolve a run's durable detail through the contract and map it — or throw
     * {@see ModelNotFoundException} when the run is unknown, so a purged/expired
     * run 404s rather than rendering an empty shell (or 500ing on the inspector's
     * not-found throw).
     *
     * `find()` is the contract's non-throwing existence check (null when
     * unknown); only when it resolves do we assemble the full detail with
     * `inspect()`. Extracted as a static so the null-guard is testable directly,
     * below the Livewire render layer.
     *
     * @return array<string, mixed>
     */
    public static function resolveDisplay(InspectsDurableRuns $inspector, string $runId): array
    {
        if ($inspector->find($runId) === null) {
            throw (new ModelNotFoundException)->setModel(SwarmDurableRun::class, [$runId]);
        }

        return DurableRunPresenter::present($inspector->inspect($runId));
    }

    public function infolist(Schema $schema): Schema
    {
        $data = $this->presented();

        /** @var array<string, string> $summary */
        $summary = $data['summary'];

        $components = [
            Section::make('Durable run')
                ->columns(2)
                ->schema([
                    TextEntry::make('run_id')->label('Run')->state($summary['run_id'])->fontFamily('mono'),
                    TextEntry::make('swarm_class')->label('Swarm')->state($summary['swarm_class']),
                    TextEntry::make('topology')->badge()->state($summary['topology']),
                    TextEntry::make('status')
                        ->badge()
                        ->color(SwarmDurableRunResource::statusColor($summary['status']))
                        ->state($summary['status']),
                    TextEntry::make('execution_mode')->label('Execution mode')->badge()->state($summary['execution_mode']),
                    TextEntry::make('coordination_profile')->label('Coordination profile')->state($summary['coordination_profile']),
                    TextEntry::make('created_at')->label('Started')->state($summary['created_at']),
                    TextEntry::make('finished_at')->label('Finished')->state($summary['finished_at']),
                ]),
            Section::make('Lifecycle')
                ->schema([
                    KeyValueEntry::make('lifecycle')
                        ->hiddenLabel()
                        ->keyLabel('Marker')
                        ->valueLabel('Value')
                        ->state($data['lifecycle']),
                ]),
            Section::make('Labels')
                ->schema([
                    KeyValueEntry::make('labels')->hiddenLabel()->state($data['labels']),
                ]),
            Section::make('Details')
                ->schema([
                    KeyValueEntry::make('details')->hiddenLabel()->state($data['details']),
                ]),
            Section::make('Parallel branches')
                ->schema([
                    RepeatableEntry::make('branches')
                        ->hiddenLabel()
                        ->state($data['branches'])
                        ->placeholder('No parallel branches.')
                        ->schema([
                            TextEntry::make('branch_id')->label('Branch')->fontFamily('mono'),
                            TextEntry::make('node_id')->label('Node'),
                            TextEntry::make('agent_class')->label('Agent'),
                            TextEntry::make('status')->badge(),
                            TextEntry::make('input'),
                            TextEntry::make('output'),
                            TextEntry::make('failure'),
                            TextEntry::make('attempts'),
                            TextEntry::make('finished_at')->label('Finished'),
                        ]),
                ]),
            Section::make('Child runs')
                ->schema([
                    RepeatableEntry::make('children')
                        ->hiddenLabel()
                        ->state($data['children'])
                        ->placeholder('No child runs.')
                        ->schema([
                            TextEntry::make('child_run_id')->label('Child run')->fontFamily('mono'),
                            TextEntry::make('child_swarm_class')->label('Swarm'),
                            TextEntry::make('wait_name')->label('Wait'),
                            TextEntry::make('status')->badge(),
                            TextEntry::make('context')->label('Context'),
                            TextEntry::make('output'),
                            TextEntry::make('failure'),
                        ]),
                ]),
            Section::make('Node outputs')
                ->schema([
                    RepeatableEntry::make('node_outputs')
                        ->hiddenLabel()
                        ->state($data['node_outputs'])
                        ->placeholder('No node outputs.')
                        ->schema([
                            TextEntry::make('node_id')->label('Node'),
                            TextEntry::make('output'),
                            TextEntry::make('created_at')->label('Recorded'),
                        ]),
                ]),
            Section::make('Waits')
                ->schema([
                    RepeatableEntry::make('waits')
                        ->hiddenLabel()
                        ->state($data['waits'])
                        ->placeholder('No waits.')
                        ->schema([
                            TextEntry::make('name')->label('Wait'),
                            TextEntry::make('status')->badge(),
                            TextEntry::make('reason'),
                            TextEntry::make('timeout_at')->label('Times out'),
                            TextEntry::make('outcome'),
                            TextEntry::make('finished_at')->label('Finished'),
                        ]),
                ]),
            Section::make('Signals')
                ->schema([
                    RepeatableEntry::make('signals')
                        ->hiddenLabel()
                        ->state($data['signals'])
                        ->placeholder('No signals.')
                        ->schema([
                            TextEntry::make('name')->label('Signal'),
                            TextEntry::make('status')->badge(),
                            TextEntry::make('payload'),
                            TextEntry::make('consumed_at')->label('Consumed'),
                        ]),
                ]),
            Section::make('Progress')
                ->schema([
                    RepeatableEntry::make('progress')
                        ->hiddenLabel()
                        ->state($data['progress'])
                        ->placeholder('No progress recorded.')
                        ->schema([
                            TextEntry::make('branch_id')->label('Branch')->fontFamily('mono'),
                            TextEntry::make('step_index')->label('Step'),
                            TextEntry::make('agent_class')->label('Agent'),
                            TextEntry::make('progress'),
                            TextEntry::make('last_progress_at')->label('Updated'),
                        ]),
                ]),
        ];

        if (is_array($data['history'])) {
            /** @var array<string, mixed> $history */
            $history = $data['history'];

            $components[] = Section::make('Run history')
                ->schema([
                    TextEntry::make('history_context')->label('Context')->state(self::historyString($history, 'context')),
                    TextEntry::make('history_output')->label('Output')->state(self::historyString($history, 'output')),
                    RepeatableEntry::make('history_steps')
                        ->label('Steps')
                        ->state(is_array($history['steps'] ?? null) ? $history['steps'] : [])
                        ->placeholder('No steps recorded.')
                        ->schema([
                            TextEntry::make('step_index')->label('#'),
                            TextEntry::make('agent_class')->label('Agent'),
                            TextEntry::make('input'),
                            TextEntry::make('output'),
                        ]),
                ]);
        }

        return $schema->components($components);
    }

    /**
     * Pull a scalar string out of the presented run-history sub-shape.
     *
     * @param  array<string, mixed>  $history
     */
    private static function historyString(array $history, string $key): string
    {
        $value = $history[$key] ?? null;

        return is_string($value) ? $value : '';
    }

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
