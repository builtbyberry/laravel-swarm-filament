<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarmFilament\Pages;

use BuiltByBerry\LaravelSwarm\Contracts\ReadableAuditOutbox;
use BuiltByBerry\LaravelSwarmFilament\Support\OutboxHealthPresenter;
use BuiltByBerry\LaravelSwarmFilament\Support\SwarmObservabilityGate;
use Filament\Infolists\Components\TextEntry;
use Filament\Pages\Page;
use Filament\Panel;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * The single-row audit outbox detail — the ONLY surface that decrypts a row's full
 * evidence payload.
 *
 * The list ({@see AuditOutboxHealth}) is payload-minimized; the payload is fetched
 * here on demand through {@see ReadableAuditOutbox::record()}, which display-
 * decrypts it per row and returns a `payload_available` flag. An undecryptable
 * payload degrades to `unavailable` rather than leaking `sw0:` ciphertext or 500ing
 * — the degrade lives in {@see OutboxHealthPresenter::presentRecord()}.
 *
 * An unknown outbox id resolves to a 404 (via {@see ModelNotFoundException}), never
 * a 500. Read-only and deny-by-default; hidden from navigation (it is a detail view
 * reached from the outbox health page).
 */
final class AuditOutboxRecord extends Page
{
    // A slug distinct from AuditOutboxHealth's: Filament derives the route NAME
    // from the slug (filament.{panel}.pages.{slug}), so a shared slug would collide
    // the two pages' route names even though their paths differ. The custom
    // getRoutePath() keeps the human-facing URL under /audit-outbox/{record}.
    protected static ?string $slug = 'audit-outbox-record';

    protected static ?string $title = 'Audit outbox record';

    protected string $view = 'filament-panels::pages.page';

    public ?int $record = null;

    /**
     * The presented (display-decrypted, degrade-safe) row, memoized so the contract
     * is read once per request.
     *
     * @var array<string, mixed>|null
     */
    private ?array $data = null;

    public static function canAccess(): bool
    {
        return SwarmObservabilityGate::allows();
    }

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public static function getRoutePath(Panel $panel): string
    {
        return '/audit-outbox/{record}';
    }

    public function mount(int|string $record): void
    {
        $this->record = (int) $record;

        // Resolve eagerly so an unknown id 404s on load rather than rendering an
        // empty shell.
        $this->data();
    }

    /**
     * Resolve a single outbox row through the contract and map it — or throw
     * {@see ModelNotFoundException} (a 404, never a 500) when the id is unknown or
     * the outbox is unavailable. Extracted as a static so the null-guard is
     * testable directly, below the Livewire render layer.
     *
     * @return array<string, mixed>
     */
    public static function resolveRecord(ReadableAuditOutbox $outbox, int $id): array
    {
        $record = $outbox->record($id);

        if ($record === null) {
            // The outbox has no Eloquent model to bind, so a plain
            // ModelNotFoundException carries the null-guard — Laravel still renders
            // it as a 404, mirroring the runs explorer's null->404 detail guard.
            throw new ModelNotFoundException("Audit outbox record [{$id}] not found.");
        }

        return OutboxHealthPresenter::presentRecord($record);
    }

    public function content(Schema $schema): Schema
    {
        $data = $this->data();

        return $schema->components([
            Section::make('Record')
                ->columns(3)
                ->schema([
                    TextEntry::make('id')->label('#')->state($data['id']),
                    TextEntry::make('category')->label('Category')->state($data['category']),
                    TextEntry::make('status')
                        ->badge()
                        ->color(OutboxHealthPresenter::statusColor(is_string($data['status']) ? $data['status'] : null))
                        ->state($data['status']),
                    TextEntry::make('run_id')->label('Run')->fontFamily('mono')->placeholder('—')->state($data['run_id']),
                    TextEntry::make('attempts')->label('Attempts')->state($data['attempts']),
                    TextEntry::make('created_at')->label('Created')->placeholder('—')->state($data['created_at']),
                    TextEntry::make('last_attempted_at')->label('Last attempted')->placeholder('—')->state($data['last_attempted_at']),
                ]),
            Section::make('Last error')->schema([
                TextEntry::make('last_error')->hiddenLabel()->state($data['last_error']),
            ]),
            Section::make('Payload')
                ->description('Display-decrypted on demand; unavailable if it could not be decrypted.')
                ->schema([
                    TextEntry::make('payload')->hiddenLabel()->fontFamily('mono')->state($data['payload']),
                ]),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function data(): array
    {
        return $this->data ??= self::resolveRecord(app(ReadableAuditOutbox::class), (int) $this->record);
    }
}
