<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Contracts\SnapshotsMemory;
use BuiltByBerry\LaravelSwarm\Memory\MemorySnapshot;
use BuiltByBerry\LaravelSwarm\Support\SwarmCapture;
use BuiltByBerry\LaravelSwarmFilament\Models\SwarmMemorySnapshot;
use BuiltByBerry\LaravelSwarmFilament\Resources\SwarmMemorySnapshotResource;
use BuiltByBerry\LaravelSwarmFilament\Resources\SwarmMemorySnapshotResource\Pages\ViewSwarmMemorySnapshot;
use BuiltByBerry\LaravelSwarmFilament\Support\MemorySnapshotPresenter;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/*
 * Component #6 — the memory viewer (index + per-snapshot detail). The index binds
 * the plaintext SwarmMemorySnapshot model (index columns only); the detail sources
 * the frozen agent-visible entries + tool calls ONLY through the v0.19
 * SnapshotsMemory::find() contract, mapped by MemorySnapshotPresenter so a redacted
 * value shows a clear [redacted] and no sw0: ciphertext can ever reach a cell.
 *
 * A frozen snapshot is the read-time realization of the propagation-policy-filtered
 * agent-visible view (every runner froze exactly what the policy presented), so this
 * surface respects propagation policy structurally without re-running the @internal
 * AgentVisibleMemoryView at read time.
 */

/** An in-memory SnapshotsMemory whose find()/allForRun() are scripted. */
function memoryViewerStore(?MemorySnapshot $snapshot): SnapshotsMemory
{
    return new class($snapshot) implements SnapshotsMemory
    {
        public function __construct(private ?MemorySnapshot $snapshot) {}

        public function snapshot(string $runId, int $stepIndex, ?array $entries = null): MemorySnapshot
        {
            return new MemorySnapshot($runId, $stepIndex, [], []);
        }

        public function appendToolCall(MemorySnapshot $snapshot, array $toolCall): MemorySnapshot
        {
            return $snapshot;
        }

        public function resetToolCalls(MemorySnapshot $snapshot): MemorySnapshot
        {
            return $snapshot;
        }

        public function find(string $runId, int $stepIndex): ?MemorySnapshot
        {
            return $this->snapshot;
        }

        public function allForRun(string $runId): array
        {
            return $this->snapshot !== null ? [$this->snapshot] : [];
        }
    };
}

/**
 * Build a MemorySnapshot value object from raw entry/tool-call rows, mirroring
 * the persisted `payload.entries` / `tool_calls` shapes.
 *
 * @param  array<int, array<string, mixed>>  $entries
 * @param  array<int, array<string, mixed>>  $toolCalls
 */
function memoryViewerSnapshot(array $entries = [], array $toolCalls = []): MemorySnapshot
{
    return MemorySnapshot::fromPersisted(
        ['run_id' => 'r1', 'step_index' => 0, 'entries' => $entries],
        $toolCalls,
        recordedAt: '2026-07-08T00:00:00+00:00',
        updatedAt: '2026-07-08T00:00:01+00:00',
    );
}

// ---------------------------------------------------------------------------
// MemorySnapshotPresenter — the single leak-proof mapping, tested in isolation.
// ---------------------------------------------------------------------------

test('the presenter renders an available memory value as its plain value', function () {
    $presented = MemorySnapshotPresenter::present(memoryViewerSnapshot([
        ['scope' => 'run', 'scope_id' => 'r1', 'key' => 'topic', 'value' => 'weather'],
    ]));

    expect($presented['entries'][0])->toBe([
        'scope' => 'run', 'scope_id' => 'r1', 'key' => 'topic', 'value' => 'weather',
    ])->and($presented['entry_count'])->toBe(1);
});

test('the presenter renders a redacted-at-write value as a clear [redacted] marker', function () {
    $presented = MemorySnapshotPresenter::present(memoryViewerSnapshot([
        ['scope' => 'run', 'scope_id' => 'r1', 'key' => 'ssn', 'value' => '[redacted]'],
    ]));

    expect($presented['entries'][0]['value'])->toBe('[redacted]');
});

test('the presenter distinguishes an absent value (none) from a redacted one', function () {
    $presented = MemorySnapshotPresenter::present(memoryViewerSnapshot([
        ['scope' => 'run', 'scope_id' => 'r1', 'key' => 'pending', 'value' => null],
    ]));

    expect($presented['entries'][0]['value'])->toBe('none');
});

test('the presenter masks a value that still looks like sw0: ciphertext, never leaking it', function () {
    // Memory is not sealed at rest, but defense in depth: an entry whose value
    // somehow carries the sealed prefix must never surface the ciphertext.
    $presented = MemorySnapshotPresenter::present(memoryViewerSnapshot([
        ['scope' => 'run', 'scope_id' => 'r1', 'key' => 'leaked', 'value' => 'sw0:ciphertext'],
    ]));

    expect($presented['entries'][0]['value'])->toBe('unavailable')
        ->and(json_encode($presented))->not->toContain('sw0:');
});

test('the presenter renders a structured (array) value as compact JSON, redaction visible inline', function () {
    $presented = MemorySnapshotPresenter::present(memoryViewerSnapshot([
        ['scope' => 'agent', 'scope_id' => 'App\\Agents\\Writer', 'key' => 'profile', 'value' => ['name' => 'Ada', 'ssn' => '[redacted]']],
    ]));

    expect($presented['entries'][0]['value'])->toBe('{"name":"Ada","ssn":"[redacted]"}');
});

test('the presenter degrades a structured value hiding a nested sw0: string, never leaking the ciphertext', function () {
    // A nested sealed string inside an array json-encodes to {"k":"sw0:..."},
    // which slips past a top-level prefix check — the whole value must degrade.
    $presented = MemorySnapshotPresenter::present(memoryViewerSnapshot([
        ['scope' => 'run', 'scope_id' => 'r1', 'key' => 'profile', 'value' => ['secret' => 'sw0:abc']],
    ]));

    expect($presented['entries'][0]['value'])->toBe('unavailable')
        ->and(json_encode($presented))->not->toContain('sw0:');
});

test('the presenter degrades a tool-call whose arguments hide a nested sw0: string', function () {
    $presented = MemorySnapshotPresenter::present(memoryViewerSnapshot([], [
        ['name' => 'search', 'arguments' => ['token' => 'sw0:abc'], 'result' => 'ok'],
    ]));

    expect($presented['tool_calls'][0]['arguments'])->toBe('unavailable')
        ->and(json_encode($presented))->not->toContain('sw0:');
});

test('the presenter maps the tool-call timeline, stringifying arguments and degrading absent results', function () {
    $presented = MemorySnapshotPresenter::present(memoryViewerSnapshot([], [
        ['name' => 'search', 'arguments' => ['q' => 'rain'], 'result' => 'sunny', 'id' => 'c1'],
        ['name' => 'noop', 'arguments' => [], 'result' => null],
    ]));

    expect($presented['tool_call_count'])->toBe(2)
        ->and($presented['tool_calls'][0])->toBe(['name' => 'search', 'arguments' => '{"q":"rain"}', 'result' => 'sunny'])
        ->and($presented['tool_calls'][1])->toBe(['name' => 'noop', 'arguments' => '[]', 'result' => 'none']);
});

test('the presenter carries the snapshot summary and tolerates malformed rows', function () {
    $presented = MemorySnapshotPresenter::present(memoryViewerSnapshot([
        'not-an-array',
        ['scope' => 'run', 'scope_id' => 'r1', 'key' => 'ok', 'value' => 'v'],
    ], ['not-an-array']));

    expect($presented['run_id'])->toBe('r1')
        ->and($presented['step_index'])->toBe(0)
        ->and($presented['recorded_at'])->toBe('2026-07-08T00:00:00+00:00')
        ->and($presented['updated_at'])->toBe('2026-07-08T00:00:01+00:00')
        // The malformed rows are skipped, not fatal.
        ->and($presented['entries'])->toHaveCount(1)
        ->and($presented['entry_count'])->toBe(1)
        ->and($presented['tool_calls'])->toBe([])
        ->and($presented['tool_call_count'])->toBe(0);
});

test('the presenter redaction sentinel matches core, guarding against drift', function () {
    // The presenter inlines the [redacted] literal (companion never couples to
    // the @internal SwarmCapture); pin it against core so a sentinel change is
    // caught here rather than silently rendering a secret as a plain value.
    expect(SwarmCapture::REDACTED)->toBe('[redacted]');

    $presented = MemorySnapshotPresenter::present(memoryViewerSnapshot([
        ['scope' => 'run', 'scope_id' => 'r1', 'key' => 'secret', 'value' => SwarmCapture::REDACTED],
    ]));

    expect($presented['entries'][0]['value'])->toBe('[redacted]');
});

// ---------------------------------------------------------------------------
// SwarmMemorySnapshotResource — read-only shape + config-driven navigation.
// ---------------------------------------------------------------------------

test('the memory resource is read-only: only index and view pages, no create/edit/delete', function () {
    expect(array_keys(SwarmMemorySnapshotResource::getPages()))->toBe(['index', 'view'])
        ->and(SwarmMemorySnapshotResource::getModel())->toBe(SwarmMemorySnapshot::class);
});

test('the memory resource navigation group is driven by config', function () {
    expect(SwarmMemorySnapshotResource::getNavigationGroup())->toBe('Swarm');

    config()->set('swarm-filament.navigation.group', 'Observability');
    expect(SwarmMemorySnapshotResource::getNavigationGroup())->toBe('Observability');
});

test('the memory resource navigation sort is driven by config, defaulting to null', function () {
    expect(SwarmMemorySnapshotResource::getNavigationSort())->toBeNull();

    config()->set('swarm-filament.navigation.sort', 4);
    expect(SwarmMemorySnapshotResource::getNavigationSort())->toBe(4);

    // A non-int config value falls back to null rather than mis-typing the sort.
    config()->set('swarm-filament.navigation.sort', 'oops');
    expect(SwarmMemorySnapshotResource::getNavigationSort())->toBeNull();
});

// ---------------------------------------------------------------------------
// ViewSwarmMemorySnapshot::resolveDisplay — the null→404 guard, below the render.
// ---------------------------------------------------------------------------

test('the detail resolve throws not-found when the snapshot is gone', function () {
    // A plaintext index row can vanish (run purged → snapshot cascaded, or cache
    // mode) — that must 404, not render an empty shell.
    ViewSwarmMemorySnapshot::resolveDisplay(memoryViewerStore(null), 'vanished-run', 0);
})->throws(ModelNotFoundException::class);

test('the detail resolve maps the snapshot through the presenter when present', function () {
    $presented = ViewSwarmMemorySnapshot::resolveDisplay(memoryViewerStore(memoryViewerSnapshot([
        ['scope' => 'run', 'scope_id' => 'r9', 'key' => 'topic', 'value' => 'weather'],
    ])), 'r9', 0);

    expect($presented['entries'][0]['value'])->toBe('weather')
        ->and($presented['entry_count'])->toBe(1);
});

// ---------------------------------------------------------------------------
// SwarmMemorySnapshot model — index-columns-only projection + read-only intent.
// ---------------------------------------------------------------------------

function memoryViewerSeed(string $runId, int $stepIndex, array $entries, array $toolCalls = []): void
{
    config()->set('swarm.persistence.driver', 'database');
    Artisan::call('migrate', ['--database' => 'testing']);

    $now = now('UTC');

    // The snapshot FK cascades from swarm_run_histories.run_id — seed the parent.
    if (DB::table('swarm_run_histories')->where('run_id', $runId)->doesntExist()) {
        DB::table('swarm_run_histories')->insert([
            'run_id' => $runId,
            'swarm_class' => 'App\\Swarms\\Example',
            'topology' => 'sequential',
            'status' => 'completed',
            'context' => json_encode(['input' => 'x']),
            'metadata' => json_encode([]),
            'steps' => json_encode([]),
            'output' => 'x',
            'usage' => json_encode([]),
            'error' => null,
            'artifacts' => json_encode([]),
            'finished_at' => $now,
            'expires_at' => $now->copy()->addHour(),
            'execution_token' => null,
            'leased_until' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    DB::table('swarm_memory_snapshots')->insert([
        'run_id' => $runId,
        'step_index' => $stepIndex,
        'payload' => json_encode(['run_id' => $runId, 'step_index' => $stepIndex, 'entries' => $entries]),
        'tool_calls' => json_encode($toolCalls),
        'created_at' => $now,
        'updated_at' => $now,
    ]);
}

test('SwarmMemorySnapshot selects only index columns and never loads the value-bearing JSON', function () {
    memoryViewerSeed('run-1', 0, [['scope' => 'run', 'scope_id' => 'run-1', 'key' => 'k', 'value' => 'v']]);

    $snapshot = SwarmMemorySnapshot::query()->first();

    expect($snapshot)->not->toBeNull()
        ->and($snapshot->run_id)->toBe('run-1')
        ->and($snapshot->step_index)->toBe(0);

    // The value-bearing JSON columns are never selected → never in the attribute
    // bag → cannot leak a memory value into a table cell.
    $attributes = $snapshot->getAttributes();
    expect($attributes)->not->toHaveKey('payload')
        ->and($attributes)->not->toHaveKey('tool_calls')
        // And the magic accessor path a Filament column would render resolves to
        // null (unloaded), never the frozen entries.
        ->and($snapshot->payload)->toBeNull()
        ->and($snapshot->tool_calls)->toBeNull();
});

test('SwarmMemorySnapshot filters and sorts natively on index columns', function () {
    memoryViewerSeed('run-a', 0, []);
    memoryViewerSeed('run-a', 1, []);
    memoryViewerSeed('run-b', 0, []);

    expect(SwarmMemorySnapshot::query()->where('run_id', 'run-a')->count())->toBe(2)
        ->and(SwarmMemorySnapshot::query()->where('run_id', 'run-a')->orderByDesc('step_index')->pluck('step_index')->all())->toBe([1, 0]);
});

test('SwarmMemorySnapshot is read-only — writes and deletes fail loud', function () {
    memoryViewerSeed('run-1', 0, []);

    $new = new SwarmMemorySnapshot;
    $new->setAttribute('run_id', 'x');
    $new->setAttribute('step_index', 0);
    expect(fn () => $new->save())->toThrow(LogicException::class, 'read-only');

    $existing = SwarmMemorySnapshot::query()->first();
    expect(fn () => $existing->delete())->toThrow(LogicException::class, 'read-only');

    expect(DB::table('swarm_memory_snapshots')->count())->toBe(1);
});

// ---------------------------------------------------------------------------
// End-to-end data path: the real SnapshotsMemory contract → presenter degrade.
// ---------------------------------------------------------------------------

test('a snapshot sourced through SnapshotsMemory::find degrades sw0: + redacted values and keeps clean ones', function () {
    config()->set('swarm.persistence.driver', 'database');
    app()->forgetInstance(SnapshotsMemory::class);

    $runId = 'memory-viewer-e2e';
    memoryViewerSeed($runId, 0, [
        ['scope' => 'run', 'scope_id' => $runId, 'key' => 'clean', 'value' => 'hello', 'metadata' => [], 'created_at' => null, 'updated_at' => null],
        ['scope' => 'run', 'scope_id' => $runId, 'key' => 'secret', 'value' => '[redacted]', 'metadata' => [], 'created_at' => null, 'updated_at' => null],
        ['scope' => 'agent', 'scope_id' => 'App\\Agents\\Writer', 'key' => 'sealed', 'value' => 'sw0:leaked', 'metadata' => [], 'created_at' => null, 'updated_at' => null],
    ], [
        ['name' => 'search', 'arguments' => ['q' => 'x'], 'result' => 'ok', 'id' => 'c1'],
    ]);

    $snapshots = app(SnapshotsMemory::class);

    // Contract read: the single snapshot and the per-run listing both resolve.
    expect($snapshots->allForRun($runId))->toHaveCount(1);

    $snapshot = $snapshots->find($runId, 0);
    expect($snapshot)->not->toBeNull();

    $presented = MemorySnapshotPresenter::present($snapshot);

    expect($presented['run_id'])->toBe($runId)
        ->and($presented['step_index'])->toBe(0)
        // Summary timestamps must survive the contract→presenter mapping, or a
        // key-name drift renders the detail header blank with a green suite.
        ->and($presented['recorded_at'])->not->toBeNull()
        ->and($presented['updated_at'])->not->toBeNull()
        ->and($presented['entry_count'])->toBe(3)
        // Clean value survives; redacted shows the marker; sw0: degrades.
        ->and($presented['entries'][0])->toMatchArray(['key' => 'clean', 'value' => 'hello'])
        ->and($presented['entries'][1])->toMatchArray(['key' => 'secret', 'value' => '[redacted]'])
        ->and($presented['entries'][2])->toMatchArray(['scope' => 'agent', 'key' => 'sealed', 'value' => 'unavailable'])
        ->and($presented['tool_calls'][0])->toBe(['name' => 'search', 'arguments' => '{"q":"x"}', 'result' => 'ok']);

    // No sw0: ciphertext survives anywhere in the presented record.
    expect(json_encode($presented))->not->toContain('sw0:');
});
