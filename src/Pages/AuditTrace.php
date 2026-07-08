<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarmFilament\Pages;

use BackedEnum;
use BuiltByBerry\LaravelSwarm\Contracts\SwarmAuditSink;
use BuiltByBerry\LaravelSwarmFilament\Support\AuditTracePresenter;
use BuiltByBerry\LaravelSwarmFilament\Support\SwarmObservabilityGate;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Pages\Page;
use Filament\Panel;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * The audit-trace timeline — a run's emitted evidence, sourced from the
 * application's bound {@see SwarmAuditSink}.
 *
 * The trail is available only when the app binds a sink implementing the optional
 * `ReadableSwarmAuditSink` contract. Core's default sink stores nothing, so out of
 * the box this page renders a clean empty-state explaining how to bind a readable
 * sink — never a bare empty table. A sink whose `forRun()` throws degrades to a
 * partial timeline plus a note, never a 500. All of that logic lives in the pure
 * {@see AuditTracePresenter}.
 *
 * The timeline is payload-minimized: it surfaces evidence metadata (category,
 * timestamp, run) only, not the full evidence envelope. Read-only and
 * deny-by-default.
 */
final class AuditTrace extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static ?string $slug = 'audit-trace';

    protected static ?string $title = 'Audit trace';

    protected string $view = 'filament-panels::pages.page';

    public ?string $run = null;

    /**
     * The presented timeline + notes, memoized so the sink is read once per request.
     *
     * @var array<string, mixed>|null
     */
    private ?array $data = null;

    public static function canAccess(): bool
    {
        return SwarmObservabilityGate::allows();
    }

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

    public static function getNavigationLabel(): string
    {
        return 'Audit trace';
    }

    public static function getRoutePath(Panel $panel): string
    {
        return '/audit-trace/{run?}';
    }

    public function mount(?string $run = null): void
    {
        $this->run = $run;
        $this->data();
    }

    /**
     * Resolve the timeline for a run through the bound sink — extracted as a static
     * so the sink classification + empty-state + degrade behavior are testable
     * directly, below the Livewire render layer.
     *
     * @return array<string, mixed>
     */
    public static function resolve(SwarmAuditSink $sink, ?string $run): array
    {
        return AuditTracePresenter::present($sink, $run);
    }

    public function content(Schema $schema): Schema
    {
        $data = $this->data();

        $components = [];

        if ($data['run_id'] !== null) {
            $components[] = Section::make('Run')->schema([
                TextEntry::make('run_id')->label('Run')->fontFamily('mono')->state($data['run_id']),
            ]);
        }

        $components[] = Section::make('Timeline')
            ->description('Evidence emitted for this run, metadata only.')
            ->schema([
                RepeatableEntry::make('records')
                    ->hiddenLabel()
                    ->state($data['records'])
                    ->placeholder('No audit trail to show.')
                    ->columns(3)
                    ->schema([
                        TextEntry::make('occurred_at')->label('When')->placeholder('—'),
                        TextEntry::make('category')->label('Category'),
                        TextEntry::make('run_id')->label('Run')->fontFamily('mono')->placeholder('—'),
                    ]),
            ]);

        $notes = $data['notes'];

        if (is_array($notes) && $notes !== []) {
            $components[] = Section::make('Notes')->schema(
                array_map(
                    static fn (string $note, int $index): TextEntry => TextEntry::make("note_{$index}")
                        ->hiddenLabel()
                        ->state($note),
                    $notes,
                    array_keys($notes),
                ),
            );
        }

        return $schema->components($components);
    }

    /**
     * @return array<string, mixed>
     */
    private function data(): array
    {
        return $this->data ??= self::resolve(app(SwarmAuditSink::class), $this->run);
    }
}
