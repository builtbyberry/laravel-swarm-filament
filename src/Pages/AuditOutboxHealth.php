<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarmFilament\Pages;

use BackedEnum;
use BuiltByBerry\LaravelSwarm\Contracts\ReadableAuditOutbox;
use BuiltByBerry\LaravelSwarmFilament\Support\OutboxHealthPresenter;
use BuiltByBerry\LaravelSwarmFilament\Support\SwarmObservabilityGate;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * The audit outbox health surface — a NON-CONSUMING view of the persisted audit
 * retry buffer.
 *
 * Every read here goes through the public {@see ReadableAuditOutbox} contract,
 * whose `healthSummary()` / `pending()` / `deadLettered()` reads are pure SELECTs:
 * they never reserve or delete rows, so this page COEXISTS with a running
 * `swarm:relay --type=audit` drainer instead of stealing its work. The companion
 * never calls `drain()` / `enqueue()`.
 *
 * The list is **payload-minimized**: it shows row metadata plus the display-
 * decrypted `last_error` only. The full evidence payload is never fetched here —
 * an operator opens a single row's {@see AuditOutboxRecord} detail to decrypt its
 * payload on demand. When the outbox is unavailable (cache persistence, or the
 * table is missing) the contract reports an empty, unavailable outbox and this
 * page renders a clean empty-state rather than erroring.
 *
 * Read-only and deny-by-default: {@see canAccess()} gates on the same
 * `viewSwarmObservability` ability as every other observability surface.
 */
final class AuditOutboxHealth extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-inbox-stack';

    protected static ?string $slug = 'audit-outbox';

    protected static ?string $title = 'Audit outbox';

    protected string $view = 'filament-panels::pages.page';

    /**
     * The presented (non-consuming, payload-minimized) health data, memoized so
     * the contract is read once per request regardless of how many entries render.
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
        return 'Audit outbox';
    }

    /**
     * Resolve the health dashboard through the contract and map it — extracted as a
     * static so the (non-consuming, payload-minimized) shape is testable directly,
     * below the Livewire render layer.
     *
     * @return array<string, mixed>
     */
    public static function resolve(ReadableAuditOutbox $outbox): array
    {
        return OutboxHealthPresenter::present(
            $outbox->healthSummary(),
            $outbox->pending(),
            $outbox->deadLettered(),
        );
    }

    public function content(Schema $schema): Schema
    {
        $data = $this->data();

        return $schema->components([
            Section::make('Health')
                ->description($data['available'] === true
                    ? 'Live counts from the audit retry buffer.'
                    : 'The audit outbox is unavailable on the current persistence driver. Set swarm.persistence.driver=database and run the package migrations to enable it.')
                ->columns(4)
                ->schema([
                    TextEntry::make('available')
                        ->label('Outbox')
                        ->badge()
                        ->color($data['available'] === true ? 'success' : 'gray')
                        ->state($data['available'] === true ? 'available' : 'unavailable'),
                    TextEntry::make('pending_count')->label('Pending')->state($data['pending_count']),
                    TextEntry::make('dead_letter_count')->label('Dead-lettered')->state($data['dead_letter_count']),
                    TextEntry::make('reserved_count')->label('Reserved by drainer')->state($data['reserved_count']),
                    TextEntry::make('oldest_pending_at')
                        ->label('Oldest pending')
                        ->placeholder('—')
                        ->state($data['oldest_pending_at']),
                ]),
            Section::make('Pending')
                ->description('Rows awaiting re-delivery. Metadata + last error only — open a row for its payload.')
                ->schema([
                    $this->rowsEntry('pending', $data['pending'], 'No pending audit records.'),
                ]),
            Section::make('Dead-lettered')
                ->description('Rows that exhausted their retry attempts. Metadata + last error only — open a row for its payload.')
                ->schema([
                    $this->rowsEntry('dead_lettered', $data['dead_lettered'], 'No dead-lettered audit records.'),
                ]),
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function rowsEntry(string $name, array $rows, string $placeholder): RepeatableEntry
    {
        return RepeatableEntry::make($name)
            ->hiddenLabel()
            ->state($rows)
            ->placeholder($placeholder)
            ->columns(5)
            ->schema([
                TextEntry::make('id')
                    ->label('#')
                    ->url(static fn (mixed $state): ?string => is_numeric($state)
                        ? AuditOutboxRecord::getUrl(['record' => (int) $state])
                        : null),
                TextEntry::make('category')->label('Category'),
                TextEntry::make('run_id')->label('Run')->placeholder('—')->fontFamily('mono'),
                TextEntry::make('status')
                    ->badge()
                    ->color(static fn (mixed $state): string => OutboxHealthPresenter::statusColor(is_string($state) ? $state : null)),
                TextEntry::make('attempts')->label('Attempts'),
                TextEntry::make('last_error')->label('Last error')->columnSpanFull(),
                TextEntry::make('created_at')->label('Created')->placeholder('—'),
            ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function data(): array
    {
        return $this->data ??= self::resolve(app(ReadableAuditOutbox::class));
    }
}
