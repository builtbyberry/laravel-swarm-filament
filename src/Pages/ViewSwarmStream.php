<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarmFilament\Pages;

use BuiltByBerry\LaravelSwarm\Contracts\StreamEventStore;
use BuiltByBerry\LaravelSwarmFilament\Models\SwarmRun;
use BuiltByBerry\LaravelSwarmFilament\Resources\SwarmRunResource;
use BuiltByBerry\LaravelSwarmFilament\Support\StreamTimelinePresenter;
use BuiltByBerry\LaravelSwarmFilament\Support\SwarmObservabilityGate;
use Filament\Infolists\Components\CodeEntry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Pages\Page;
use Filament\Panel;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * The streaming / causal-log viewer: a per-run timeline of the append-only causal
 * log, grouped by node with void-edge markers.
 *
 * A standalone Filament page (not a resource) keyed by run id — the run is the
 * entry point, reached from the runs explorer's row action, so no separate index
 * is needed. The log is read ONLY through the public
 * {@see StreamEventStore::events()} contract and folded by the pure
 * {@see StreamTimelinePresenter} — this page never touches core internals or the
 * `@internal` cipher. Payloads are rendered defensively (a still-`sw0:` value is
 * masked) even though this substrate is plaintext-but-capture-redacted.
 *
 * Deny-by-default authorization: as a page (whose Filament auth hook differs from
 * a resource's) it calls {@see SwarmObservabilityGate::allows()} in
 * {@see canAccess()} directly, rather than the resource trait. It is not a
 * navigation destination on its own — {@see shouldRegisterNavigation()} is false;
 * it is always reached from a specific run.
 */
class ViewSwarmStream extends Page
{
    protected static ?string $slug = 'swarm-streams';

    /**
     * The resolved page data: the run summary header and the folded timeline.
     * Public so Livewire hydrates it across the request; every member is a plain
     * scalar/array, so it round-trips cleanly.
     *
     * @var array<string, mixed>
     */
    public array $data = [];

    public static function getRoutePath(Panel $panel): string
    {
        // Keyed by run id; the run is the entry point, reached from the runs list.
        return '/swarm-streams/{record}';
    }

    public static function canAccess(): bool
    {
        return SwarmObservabilityGate::allows();
    }

    public static function shouldRegisterNavigation(): bool
    {
        // Reached from a specific run in the runs explorer, never from the nav.
        return false;
    }

    public function getTitle(): string
    {
        return 'Stream timeline';
    }

    public function mount(string $record): void
    {
        $this->data = self::resolve(
            app(StreamEventStore::class),
            self::runSummary(SwarmRun::query()->whereKey($record)->first()),
            $record,
        );
    }

    /**
     * Resolve a run's timeline through the {@see StreamEventStore} contract.
     *
     * Distinguishes a run that simply never streamed (its history row exists, or
     * some events are present → render, possibly with an empty timeline) from a
     * genuinely unknown run (no history row AND no events → 404, never a 500 or a
     * blank shell). Extracted as a static so the guard is testable below the
     * Livewire render layer.
     *
     * @param  array<string, mixed>|null  $runSummary  the plaintext run header, or null when no history row exists
     * @return array{run: array<string, mixed>, timeline: array<string, mixed>}
     */
    public static function resolve(StreamEventStore $store, ?array $runSummary, string $runId): array
    {
        $timeline = StreamTimelinePresenter::present($store->events($runId));

        $hasAny = $timeline['event_count'] > 0 || $timeline['void_edge_count'] > 0;

        if ($runSummary === null && ! $hasAny) {
            throw (new ModelNotFoundException)->setModel(SwarmRun::class, [$runId]);
        }

        return [
            'run' => $runSummary ?? [
                'run_id' => $runId,
                'swarm_label' => null,
                'status' => null,
                'status_color' => 'gray',
                'topology' => null,
            ],
            'timeline' => $timeline,
        ];
    }

    public function content(Schema $schema): Schema
    {
        $run = is_array($this->data['run'] ?? null) ? $this->data['run'] : [];
        $timeline = is_array($this->data['timeline'] ?? null) ? $this->data['timeline'] : [];
        $nodes = is_array($timeline['nodes'] ?? null) ? $timeline['nodes'] : [];

        $components = [$this->runSection($run)];

        if ($nodes === []) {
            $components[] = Section::make('Timeline')->schema([
                TextEntry::make('empty')
                    ->hiddenLabel()
                    ->state('No streaming events recorded for this run.'),
            ]);

            return $schema->components($components);
        }

        foreach ($nodes as $index => $node) {
            if (is_array($node)) {
                $components[] = $this->nodeSection((int) $index, $node);
            }
        }

        return $schema->components($components);
    }

    /**
     * @param  array<string, mixed>  $run
     */
    private function runSection(array $run): Section
    {
        $statusColor = is_string($run['status_color'] ?? null) ? $run['status_color'] : 'gray';

        return Section::make('Run')
            ->columns(3)
            ->schema([
                TextEntry::make('run_id')->label('Run')->state($run['run_id'] ?? null)->fontFamily('mono'),
                TextEntry::make('swarm')->label('Swarm')->state($run['swarm_label'] ?? null)->placeholder('—'),
                TextEntry::make('status')->badge()->color($statusColor)->state($run['status'] ?? null)->placeholder('—'),
            ]);
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private function nodeSection(int $index, array $node): Section
    {
        $label = is_string($node['label'] ?? null) ? $node['label'] : 'Node';
        $count = is_int($node['event_count'] ?? null) ? $node['event_count'] : 0;
        $events = is_array($node['events'] ?? null) ? array_values($node['events']) : [];

        return Section::make($label)
            ->description($count === 1 ? '1 event' : $count.' events')
            ->collapsible()
            ->schema([
                RepeatableEntry::make('node_'.$index)
                    ->hiddenLabel()
                    ->state($events)
                    ->placeholder('No events under this node.')
                    ->schema([
                        TextEntry::make('label')->hiddenLabel()->badge()->color('gray'),
                        TextEntry::make('summary')->hiddenLabel()->columnSpanFull(),
                        TextEntry::make('marker')
                            ->hiddenLabel()
                            ->badge(fn (?string $state): bool => filled($state))
                            ->color('warning')
                            ->columnSpanFull(),
                        CodeEntry::make('payload')
                            ->hiddenLabel()
                            ->grammar('json')
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    /**
     * Map the plaintext run model into the display header, reusing the runs
     * explorer's label/status transforms so the two surfaces never diverge.
     *
     * @return array<string, mixed>|null
     */
    private static function runSummary(?SwarmRun $run): ?array
    {
        if ($run === null) {
            return null;
        }

        $status = is_string($run->status) ? $run->status : null;

        return [
            'run_id' => (string) $run->getKey(),
            'swarm_label' => SwarmRunResource::swarmLabel(is_string($run->swarm_class) ? $run->swarm_class : null),
            'status' => $status,
            'status_color' => SwarmRunResource::statusColor($status),
            'topology' => is_string($run->topology) ? $run->topology : null,
        ];
    }
}
