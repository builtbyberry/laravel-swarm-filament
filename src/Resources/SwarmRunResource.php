<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarmFilament\Resources;

use BackedEnum;
use BuiltByBerry\LaravelSwarm\Contracts\ReadableRunHistoryStore;
use BuiltByBerry\LaravelSwarmFilament\Models\SwarmRun;
use BuiltByBerry\LaravelSwarmFilament\Resources\SwarmRunResource\Pages;
use BuiltByBerry\LaravelSwarmFilament\Support\RunDisplayPresenter;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\PageRegistration;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Str;

/**
 * The runs explorer — a read-only index of Swarm runs with a per-run detail view.
 *
 * The INDEX table binds the {@see SwarmRun} model, which selects only the
 * plaintext display columns, so filtering / sorting / pagination run natively in
 * the database and no sealed column is ever projected into a table cell. The
 * per-run DETAIL view ({@see Pages\ViewSwarmRun}) sources every sealed field
 * through the `ReadableRunHistoryStore::findForDisplay()` display contract — never
 * this model — so the run context, run output, and per-step IO are display-
 * decrypted per field and degrade to a placeholder rather than leaking `sw0:`.
 *
 * Strictly read-only: the resource registers only index + view pages (no create /
 * edit / delete routes), and inherits the deny-by-default authorization gate from
 * {@see SwarmResource}.
 */
final class SwarmRunResource extends SwarmResource
{
    protected static ?string $model = SwarmRun::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-rectangle-stack';

    /**
     * Per-request memo of run-id → summary, so the index resolves each visible
     * row's display record at most once.
     *
     * @var array<string, ?string>
     */
    private static array $summaryCache = [];

    public static function getModelLabel(): string
    {
        return 'Swarm run';
    }

    public static function getPluralModelLabel(): string
    {
        return 'Swarm runs';
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                // What the run did — the plain-language line, with the swarm/topology
                // as the secondary. The gist is display-decrypted per visible row.
                TextColumn::make('run_id')
                    ->label('Run')
                    ->state(static fn (SwarmRun $record): string => self::runSummary($record->run_id)
                        ?? self::swarmLabel($record->swarm_class).' run')
                    ->description(static fn (SwarmRun $record): string => self::swarmLabel($record->swarm_class).' · '.$record->topology)
                    ->weight('medium')
                    ->searchable(['run_id', 'swarm_class'])
                    ->wrap(),
                TextColumn::make('status')
                    ->badge()
                    ->color(static fn (?string $state): string => self::statusColor($state))
                    ->sortable(),
                TextColumn::make('duration')
                    ->label('Duration')
                    ->state(static fn (SwarmRun $record): string => self::durationLabel($record))
                    ->alignEnd(),
                TextColumn::make('tokens')
                    ->label('Tokens')
                    ->state(static fn (SwarmRun $record): string => self::tokensLabel($record))
                    ->alignEnd(),
                TextColumn::make('created_at')
                    ->label('Started')
                    ->since()
                    ->sortable()
                    ->alignEnd(),
            ])
            ->filters([
                SelectFilter::make('status')->options([
                    'running' => 'Running',
                    'completed' => 'Completed',
                    'failed' => 'Failed',
                ]),
            ])
            ->recordActions([
                ViewAction::make()->label('Open'),
            ])
            ->recordUrl(static fn (SwarmRun $record): string => Pages\ViewSwarmRun::getUrl(['record' => $record->getKey()]))
            ->defaultSort('created_at', 'desc');
    }

    /**
     * The scan line for an index row — what the run was *asked to do* (its request),
     * which is the strongest recognizer when scanning a list. Falls back to the
     * output gist, then to null (the caller shows the swarm name). Read per visible
     * row through the display contract (never the model's sealed columns) and
     * memoized per request. The full request → flow → outcome story is the run page.
     */
    public static function runSummary(string $runId): ?string
    {
        if (array_key_exists($runId, self::$summaryCache)) {
            return self::$summaryCache[$runId];
        }

        $display = app(ReadableRunHistoryStore::class)->findForDisplay($runId);
        $summary = null;

        if ($display !== null) {
            $presented = RunDisplayPresenter::present($display);
            // Prefer the request (the run's intent); fall back to the output gist.
            $summary = self::gist($presented['context'] ?? null) ?? self::gist($presented['output'] ?? null);
        }

        return self::$summaryCache[$runId] = $summary;
    }

    /**
     * A single clipped line from a display string, or null when it's absent,
     * redacted, or undecryptable.
     */
    private static function gist(mixed $value): ?string
    {
        if (! is_string($value) || in_array($value, ['unavailable', 'none', '[redacted]', ''], true)) {
            return null;
        }

        $line = trim((string) preg_replace(['/^\[[^\]]+\]\s*/', '/\s+/'], ['', ' '], $value));

        return $line === '' ? null : Str::limit($line, 90);
    }

    /**
     * Wall-clock duration of a run, or an em dash when it hasn't finished / is
     * sub-second.
     */
    public static function durationLabel(SwarmRun $record): string
    {
        if ($record->created_at === null || $record->finished_at === null) {
            return '—';
        }

        $seconds = $record->finished_at->getTimestamp() - $record->created_at->getTimestamp();

        return $seconds > 0 ? $seconds.'s' : '—';
    }

    /**
     * Total prompt+completion tokens for a run from the plaintext usage summary,
     * or an em dash when there were none (e.g. a scripted, non-LLM run).
     */
    public static function tokensLabel(SwarmRun $record): string
    {
        $usage = $record->usage;
        $tokens = (is_numeric($usage['prompt_tokens'] ?? null) ? (int) $usage['prompt_tokens'] : 0)
            + (is_numeric($usage['completion_tokens'] ?? null) ? (int) $usage['completion_tokens'] : 0);

        return $tokens > 0 ? number_format($tokens) : '—';
    }

    /**
     * @return array<string, PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSwarmRuns::route('/'),
            'view' => Pages\ViewSwarmRun::route('/{record}'),
        ];
    }

    /**
     * A Filament color token for a run status. Shared by the index badge and the
     * detail-view status entry so the two never diverge.
     */
    public static function statusColor(?string $status): string
    {
        return match ($status) {
            'completed' => 'success',
            'failed' => 'danger',
            'running' => 'warning',
            default => 'gray',
        };
    }
}
