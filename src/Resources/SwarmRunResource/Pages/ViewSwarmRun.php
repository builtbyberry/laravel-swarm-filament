<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarmFilament\Resources\SwarmRunResource\Pages;

use BuiltByBerry\LaravelSwarm\Contracts\ReadableRunHistoryStore;
use BuiltByBerry\LaravelSwarm\Contracts\SnapshotsMemory;
use BuiltByBerry\LaravelSwarm\Contracts\StreamEventStore;
use BuiltByBerry\LaravelSwarm\Contracts\SwarmAuditSink;
use BuiltByBerry\LaravelSwarmFilament\Models\SwarmRun;
use BuiltByBerry\LaravelSwarmFilament\Resources\SwarmRunResource;
use BuiltByBerry\LaravelSwarmFilament\Support\AuditTracePresenter;
use BuiltByBerry\LaravelSwarmFilament\Support\MemoryFacets;
use BuiltByBerry\LaravelSwarmFilament\Support\RunDisplayPresenter;
use BuiltByBerry\LaravelSwarmFilament\Support\RunGraph;
use BuiltByBerry\LaravelSwarmFilament\Support\StreamTimelinePresenter;
use BuiltByBerry\LaravelSwarmFilament\Support\WorkflowGraphPresenter;
use Filament\Actions\Action;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;

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

        // Fold the run's memory snapshots into the flow: each step node carries the
        // keys it wrote, the memory it could see, and the tools it called — so the
        // retired global "Memory Snapshots" list becomes a facet of the run.
        $runId = (string) $this->getRecord()->getKey();
        $facets = MemoryFacets::forRun(app(SnapshotsMemory::class), $runId);
        $graph = RunGraph::fromRun($data, $facets);

        // Streaming and audit are facets of THIS run, folded in as sections — not
        // separate destinations. Both degrade to nothing/an empty-state when the
        // run produced no stream events / the app binds no readable audit sink.
        $stream = StreamTimelinePresenter::present(app(StreamEventStore::class)->events($runId));
        $audit = AuditTracePresenter::present(app(SwarmAuditSink::class), $runId);

        $components = [
            // The flow is the page: a one-line headline (the "so what"), the request
            // that started it, the annotated graph (click a step for full I/O), and
            // the final output. Per-step detail lives in the graph, not a table.
            Section::make(self::headline($data))
                ->description(is_string($data['context'] ?? null) ? $data['context'] : null)
                ->schema([
                    ViewEntry::make('workflow')
                        ->hiddenLabel()
                        ->view('swarm-filament::graph')
                        ->state(WorkflowGraphPresenter::present($graph['nodes'], $graph['edges'])),
                ]),
            Section::make('Final output')
                ->collapsible()
                ->schema([
                    TextEntry::make('output')->hiddenLabel()->state($data['output']),
                ]),
        ];

        if ((int) ($stream['node_count'] ?? 0) > 0) {
            $components[] = Section::make('Streaming')
                ->description('The per-node causal log of what streamed while this run executed.')
                ->collapsed()
                ->schema([
                    ViewEntry::make('streaming')->hiddenLabel()->view('swarm-filament::timeline')->state($stream),
                ]);
        }

        $components[] = Section::make('Audit trail')
            ->description('Evidence this run emitted to the application\'s audit sink.')
            ->collapsed()
            ->schema(self::auditSchema($audit));

        $components[] = Section::make('Run details')
            ->collapsed()
            ->columns(2)
            ->schema([
                TextEntry::make('run_id')->label('Run')->state($data['run_id'])->fontFamily('mono')->copyable(),
                TextEntry::make('swarm_class')->label('Swarm')->state($data['swarm_class']),
                TextEntry::make('started_at')->label('Started')->dateTime()->state($data['started_at']),
                TextEntry::make('finished_at')->label('Finished')->dateTime()->placeholder('—')->state($data['finished_at']),
            ]);

        // The run reads as one story top-to-bottom — a single full-width column, not
        // the default two-column grid that scatters the sections.
        return $schema->components($components)->columns(1);
    }

    /**
     * The Audit trail section content: the run's evidence records when a readable
     * sink is bound, otherwise the presenter's plain-language note (e.g. core's
     * default sink stores nothing).
     *
     * @param  array<string, mixed>  $audit  an {@see AuditTracePresenter::present()} record
     * @return list<Component>
     */
    private static function auditSchema(array $audit): array
    {
        $records = is_array($audit['records'] ?? null) ? $audit['records'] : [];
        $notes = is_array($audit['notes'] ?? null) ? array_values(array_filter($audit['notes'], 'is_string')) : [];

        if ($records === []) {
            return [
                TextEntry::make('audit_note')->hiddenLabel()
                    ->state($notes[0] ?? 'No audit evidence recorded for this run.'),
            ];
        }

        return [
            RepeatableEntry::make('audit')
                ->hiddenLabel()
                ->state($records)
                ->schema([
                    TextEntry::make('category')->badge(),
                    TextEntry::make('occurred_at')->label('When')->dateTime()->placeholder('—'),
                ]),
        ];
    }

    /**
     * The plain-language "so what" line for the run — outcome, shape, and the
     * cost/latency metrics when there are any to report.
     *
     * @param  array<string, mixed>  $data
     */
    private static function headline(array $data): string
    {
        $metrics = is_array($data['metrics'] ?? null) ? $data['metrics'] : [];
        $swarm = is_string($data['swarm_class'] ?? null) ? class_basename($data['swarm_class']) : 'Run';

        $parts = array_filter([
            is_string($data['status'] ?? null) ? ucfirst($data['status']) : null,
            is_string($data['topology'] ?? null) ? $data['topology'] : null,
            ($metrics['steps'] ?? 0).' '.Str::plural('step', $metrics['steps'] ?? 0),
            isset($metrics['duration']) && is_string($metrics['duration']) ? $metrics['duration'] : null,
            isset($metrics['tokens']) && is_int($metrics['tokens']) ? number_format($metrics['tokens']).' tokens' : null,
        ]);

        return $swarm.' · '.implode(' · ', $parts);
    }

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
