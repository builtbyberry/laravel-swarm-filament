<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarmFilament\Resources\SwarmRunResource\Pages;

use BuiltByBerry\LaravelSwarm\Contracts\InspectsDurableRuns;
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

    /**
     * Resolve the workflow flow for a run, preferring the TRUE authored DAG.
     *
     * A durable (static-)hierarchical run persists its declared `route_plan`, whose
     * edges capture the real topology — parallel fan-out, joins, finish — that a
     * flat run-history step list cannot ({@see RunGraph::fromRoutePlan()}). For any
     * other run (non-durable, or durable without a plan), and whenever durable
     * inspection is unbound or fails, fall back to the topology-derived flow over
     * run history ({@see RunGraph::fromRun()}).
     *
     * @param  array<string, mixed>  $data  the display record
     * @param  array<int, array<string, mixed>>  $facets
     * @return array{nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>}
     */
    private static function resolveFlow(string $runId, array $data, array $facets): array
    {
        try {
            $durable = app(InspectsDurableRuns::class);

            if ($durable->find($runId) !== null) {
                $run = $durable->inspect($runId)->run ?? [];
                $plan = $run['route_plan'] ?? null;
                $plan = is_string($plan) ? json_decode($plan, true) : $plan;

                if (is_array($plan) && is_array($plan['nodes'] ?? null) && $plan['nodes'] !== []) {
                    $completed = is_array($run['completed_node_ids'] ?? null) ? array_values(array_filter($run['completed_node_ids'], 'is_string')) : [];
                    $status = is_string($run['status'] ?? null) ? $run['status'] : null;

                    return RunGraph::fromRoutePlan($plan, $completed, $status);
                }
            }
        } catch (\Throwable) {
            // Durable inspection unavailable or failed — fall back to run history.
        }

        return RunGraph::fromRun($data, $facets);
    }

    public function infolist(Schema $schema): Schema
    {
        $data = $this->presented();

        // Fold the run's memory snapshots into the flow: each step node carries the
        // keys it wrote, the memory it could see, and the tools it called — so the
        // retired global "Memory Snapshots" list becomes a facet of the run.
        $runId = (string) $this->getRecord()->getKey();
        $facets = MemoryFacets::forRun(app(SnapshotsMemory::class), $runId);
        $graph = self::resolveFlow($runId, $data, $facets);

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
                    // Agent output is frequently Markdown (headings, lists) — render it
                    // as such. Plain-text and the degrade sentinels pass through cleanly.
                    TextEntry::make('output')->hiddenLabel()->markdown()->state($data['output']),
                ]),
        ];

        // A failed run must show its "why", not just a red status: the captured
        // terminal failure (message + exception class), display-decrypted and
        // degrade-safe. Shown whenever the run errored — an error payload is
        // present, or the status is `failed` even if no detail was captured.
        if (self::hasFailure($data)) {
            array_splice($components, 1, 0, [
                Section::make('Failure')
                    ->description('Why this run ended in a failed state.')
                    ->schema(self::failureSchema($data)),
            ]);
        }

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

        // Run-produced artifacts as a read-only facet — no control actions. Always
        // present (collapsed) with an empty-state, so the surface is discoverable
        // even for runs that captured none. Content is degrade-safe (sealed leaves
        // masked in RunDisplayPresenter before it reaches the partial).
        $components[] = Section::make('Artifacts')
            ->description('Read-only outputs this run captured.')
            ->collapsed()
            ->schema([
                ViewEntry::make('artifacts')->hiddenLabel()->view('swarm-filament::artifacts')->state($data['artifacts']),
            ]);

        $metadata = $data['run_metadata'];

        $components[] = Section::make('Run details')
            ->collapsed()
            ->columns(2)
            ->schema([
                TextEntry::make('run_id')->label('Run')->state($data['run_id'])->fontFamily('mono')->copyable(),
                TextEntry::make('swarm_class')->label('Swarm')->state($data['swarm_class']),
                TextEntry::make('started_at')->label('Started')->dateTime()->state($data['started_at']),
                TextEntry::make('finished_at')->label('Finished')->dateTime()->placeholder('—')->state($data['finished_at']),
                // Operational metadata: lineage, execution mode, and tags. Plain-text
                // index values (never sealed IO), placeholdered when absent.
                TextEntry::make('parent_run_id')->label('Parent run')->state($metadata['parent_run_id'])->fontFamily('mono')->placeholder('—'),
                TextEntry::make('execution_mode')->label('Execution mode')->state($metadata['execution_mode'])->placeholder('—'),
                TextEntry::make('tags')->label('Tags')->state($metadata['tags'])->placeholder('—'),
            ]);

        // The run reads as one story top-to-bottom — a single full-width column, not
        // the default two-column grid that scatters the sections.
        return $schema->components($components)->columns(1);
    }

    /**
     * The Audit trail section content: a compact one-line-per-event timeline of the
     * run's emitted evidence when a readable sink is bound, otherwise the presenter's
     * plain-language empty-state note (e.g. core's default sink stores nothing). Both
     * cases are handled inside the timeline partial from the presenter record.
     *
     * @param  array<string, mixed>  $audit  an {@see AuditTracePresenter::present()} record
     * @return list<Component>
     */
    private static function auditSchema(array $audit): array
    {
        return [
            ViewEntry::make('audit')
                ->hiddenLabel()
                ->view('swarm-filament::audit-timeline')
                ->state($audit),
        ];
    }

    /**
     * Whether the run should surface a Failure section: a captured `error`
     * payload is present, or the run reached a `failed` status even if no failure
     * detail was captured (Skip policy / an unstamped error).
     *
     * @param  array<string, mixed>  $data
     */
    private static function hasFailure(array $data): bool
    {
        return ($data['error'] ?? null) !== null || ($data['status'] ?? null) === 'failed';
    }

    /**
     * The Failure section content: the captured exception class and message. Both
     * come pre-degraded from {@see RunDisplayPresenter} — the message is routed
     * through the sealed chokepoint there, so no `sw0:` ciphertext reaches here.
     * When the run failed without a captured payload, the class placeholder still
     * gives the "why is this red" answer rather than an empty section.
     *
     * @param  array<string, mixed>  $data
     * @return list<Component>
     */
    private static function failureSchema(array $data): array
    {
        $error = is_array($data['error'] ?? null) ? $data['error'] : [];

        return [
            TextEntry::make('failure_class')
                ->label('Exception')
                ->state(is_string($error['class'] ?? null) ? $error['class'] : null)
                ->fontFamily('mono')
                ->placeholder('No failure detail was captured for this run.'),
            TextEntry::make('failure_message')
                ->label('Message')
                ->state(is_string($error['message'] ?? null) ? $error['message'] : null)
                ->placeholder('—'),
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
