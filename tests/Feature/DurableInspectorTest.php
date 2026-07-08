<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Contracts\InspectsDurableRuns;
use BuiltByBerry\LaravelSwarm\Persistence\DatabaseDurableRunStore;
use BuiltByBerry\LaravelSwarm\Persistence\SwarmPersistenceCipher;
use BuiltByBerry\LaravelSwarm\Responses\DurableRunDetail;
use BuiltByBerry\LaravelSwarmFilament\Models\SwarmDurableRun;
use BuiltByBerry\LaravelSwarmFilament\Resources\SwarmDurableRunResource;
use BuiltByBerry\LaravelSwarmFilament\Resources\SwarmDurableRunResource\Pages\ViewSwarmDurableRun;
use BuiltByBerry\LaravelSwarmFilament\Support\DurableRunPresenter;
use BuiltByBerry\LaravelSwarmFilament\SwarmFilamentPlugin;
use Filament\Panel;
use Illuminate\Config\Repository;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Psr\Log\NullLogger;

/*
 * Component #5 — the durable run inspector (index + per-run detail). The index
 * binds the plaintext SwarmDurableRun model; the detail is assembled ONLY
 * through the v0.19 InspectsDurableRuns::inspect() contract and mapped by
 * DurableRunPresenter, so no sw0: ciphertext can ever reach a rendered cell.
 */

/** A sealed sw0: value under a foreign key — undecryptable here, so it degrades. */
function durableInspectorPoison(string $plaintext = 'unreadable'): string
{
    $foreign = new SwarmPersistenceCipher(
        new Repository(['swarm.persistence.encrypt_at_rest' => true, 'swarm.persistence.driver' => 'database']),
        new Encrypter(random_bytes(32), 'aes-256-cbc'),
        new NullLogger,
    );

    return (string) $foreign->seal($plaintext);
}

/** An in-memory InspectsDurableRuns whose find()/inspect() are scripted. */
function durableInspectorFake(?array $run, ?DurableRunDetail $detail = null): InspectsDurableRuns
{
    return new class($run, $detail) implements InspectsDurableRuns
    {
        public function __construct(private ?array $run, private ?DurableRunDetail $detail) {}

        public function find(string $runId): ?array
        {
            return $this->run;
        }

        public function inspect(string $runId): DurableRunDetail
        {
            return $this->detail ?? new DurableRunDetail(runId: $runId, run: $this->run);
        }

        public function inspectByLabels(array $labels, int $limit = 50): array
        {
            return [];
        }
    };
}

/** Seed a durable run row (mirrors core's DisplayReadSeamsTest seeding). */
function durableInspectorSeedRun(string $runId, string $status = 'completed'): void
{
    $now = now('UTC');

    DB::table('swarm_durable_runs')->insert([
        'run_id' => $runId,
        'swarm_class' => 'App\\Swarms\\Example',
        'topology' => 'hierarchical',
        'execution_mode' => 'durable',
        'coordination_profile' => 'step_durable',
        'status' => $status,
        'next_step_index' => 2,
        'current_step_index' => 1,
        'total_steps' => 3,
        'route_cursor' => null,
        'route_start_node_id' => null,
        'current_node_id' => null,
        'completed_node_ids' => json_encode([]),
        'timeout_at' => $now->copy()->addHour(),
        'step_timeout_seconds' => 300,
        'attempts' => 4,
        'lease_acquired_at' => null,
        'execution_token' => null,
        'leased_until' => null,
        'recovery_count' => 1,
        'last_recovered_at' => null,
        'pause_requested_at' => null,
        'paused_at' => null,
        'resumed_at' => null,
        'cancel_requested_at' => null,
        'cancelled_at' => null,
        'timed_out_at' => null,
        'wait_reason' => null,
        'waiting_since' => null,
        'wait_timeout_at' => null,
        'last_progress_at' => null,
        'retry_attempt' => 0,
        'next_retry_at' => null,
        'parent_run_id' => null,
        'queue_connection' => null,
        'queue_name' => null,
        'finished_at' => $status === 'completed' ? $now : null,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
}

// ---------------------------------------------------------------------------
// DurableRunPresenter — the single leak-proof mapping, tested in isolation.
// ---------------------------------------------------------------------------

test('the presenter maps the run summary and lifecycle markers from the run row', function () {
    $detail = new DurableRunDetail(
        runId: 'r1',
        run: [
            'swarm_class' => 'App\\Swarms\\Demo',
            'topology' => 'hierarchical',
            'status' => 'running',
            'execution_mode' => 'durable',
            'coordination_profile' => 'step_durable',
            'attempts' => 3,
            'total_steps' => 5,
            'created_at' => '2026-07-08 10:00:00',
            'finished_at' => null,
            'parent_run_id' => 'parent-1',
        ],
    );

    $presented = DurableRunPresenter::present($detail);

    expect($presented['run_id'])->toBe('r1')
        ->and($presented['summary']['swarm_class'])->toBe('App\\Swarms\\Demo')
        ->and($presented['summary']['status'])->toBe('running')
        ->and($presented['summary']['execution_mode'])->toBe('durable')
        ->and($presented['summary']['coordination_profile'])->toBe('step_durable')
        ->and($presented['summary']['created_at'])->toBe('2026-07-08 10:00:00')
        // A null timestamp renders `none`, not an empty string.
        ->and($presented['summary']['finished_at'])->toBe('none')
        // Lifecycle markers: present ints stringify, absent markers render `none`.
        ->and($presented['lifecycle']['attempts'])->toBe('3')
        ->and($presented['lifecycle']['total_steps'])->toBe('5')
        ->and($presented['lifecycle']['parent_run_id'])->toBe('parent-1')
        ->and($presented['lifecycle']['cancelled_at'])->toBe('none');
});

test('the presenter renders available sealed branch/child/node fields as their decrypted values', function () {
    $detail = new DurableRunDetail(
        runId: 'r1',
        run: [],
        branches: [[
            'branch_id' => 'b1', 'step_index' => 1, 'node_id' => 'n1', 'agent_class' => 'Writer',
            'status' => 'completed', 'input' => 'branch in', 'input_available' => true,
            'output' => 'branch out', 'output_available' => true,
        ]],
        children: [[
            'child_run_id' => 'c1', 'child_swarm_class' => 'App\\Swarms\\Child', 'wait_name' => 'child',
            'status' => 'completed', 'context_payload' => 'child ctx', 'context_available' => true,
            'output' => 'child out', 'output_available' => true,
        ]],
        hierarchicalNodeOutputs: [[
            'node_id' => 'node-x', 'output' => 'node out', 'output_available' => true,
        ]],
    );

    $presented = DurableRunPresenter::present($detail);

    expect($presented['branches'][0]['input'])->toBe('branch in')
        ->and($presented['branches'][0]['output'])->toBe('branch out')
        ->and($presented['branches'][0]['agent_class'])->toBe('Writer')
        ->and($presented['children'][0]['context'])->toBe('child ctx')
        ->and($presented['children'][0]['output'])->toBe('child out')
        ->and($presented['node_outputs'][0]['output'])->toBe('node out');
});

test('the presenter degrades an undecryptable sealed field to unavailable', function () {
    $detail = new DurableRunDetail(
        runId: 'r1',
        run: [],
        branches: [[
            'branch_id' => 'b1', 'input' => null, 'input_available' => false,
            'output' => null, 'output_available' => false,
        ]],
        hierarchicalNodeOutputs: [[
            'node_id' => 'n', 'output' => null, 'output_available' => false,
        ]],
    );

    $presented = DurableRunPresenter::present($detail);

    expect($presented['branches'][0]['input'])->toBe('unavailable')
        ->and($presented['branches'][0]['output'])->toBe('unavailable')
        ->and($presented['node_outputs'][0]['output'])->toBe('unavailable');
});

test('the presenter distinguishes an absent field (none) from an undecryptable one (unavailable)', function () {
    $detail = new DurableRunDetail(
        runId: 'r1',
        run: [],
        // available flag true but nothing stored — a not-yet-produced field.
        branches: [[
            'branch_id' => 'b1', 'input' => null, 'input_available' => true,
            'output' => null, 'output_available' => true,
        ]],
    );

    $presented = DurableRunPresenter::present($detail);

    expect($presented['branches'][0]['input'])->toBe('none')
        ->and($presented['branches'][0]['output'])->toBe('none');
});

test('the presenter masks a value that still looks like sw0: ciphertext even if flagged available', function () {
    $detail = new DurableRunDetail(
        runId: 'r1',
        run: [],
        hierarchicalNodeOutputs: [[
            'node_id' => 'n', 'output' => 'sw0:leaked', 'output_available' => true,
        ]],
    );

    expect(DurableRunPresenter::present($detail)['node_outputs'][0]['output'])->toBe('unavailable');
});

test('the presenter recursively masks a sealed value nested inside a JSON-ish map', function () {
    $detail = new DurableRunDetail(
        runId: 'r1',
        run: [],
        details: ['note' => 'ok', 'secret' => 'sw0:nested-ciphertext'],
        signals: [[
            'name' => 'sig', 'status' => 'recorded',
            'payload' => ['by' => 'alice', 'token' => 'sw0:sealed-token'],
        ]],
    );

    $presented = DurableRunPresenter::present($detail);

    // The detail map value that was ciphertext is masked, the clean one survives.
    expect($presented['details']['note'])->toBe('ok')
        ->and($presented['details']['secret'])->toBe('unavailable');

    // The signal payload JSON never serializes the sealed nested value.
    expect($presented['signals'][0]['payload'])->toContain('alice')
        ->and($presented['signals'][0]['payload'])->not->toContain('sw0:')
        ->and($presented['signals'][0]['payload'])->toContain('unavailable');

    // Belt and suspenders: no sw0: anywhere in the presented record.
    expect(json_encode($presented))->not->toContain('sw0:');
});

test('the presenter maps waits, signals, and progress rows', function () {
    $detail = new DurableRunDetail(
        runId: 'r1',
        run: [],
        waits: [['name' => 'approval', 'status' => 'waiting', 'reason' => 'sign-off', 'outcome' => null]],
        signals: [['name' => 'approved', 'status' => 'consumed', 'payload' => ['by' => 'bob']]],
        progress: [['branch_id' => 'b1', 'step_index' => 2, 'agent_class' => 'Worker', 'progress' => ['pct' => 50]]],
    );

    $presented = DurableRunPresenter::present($detail);

    expect($presented['waits'][0]['name'])->toBe('approval')
        ->and($presented['waits'][0]['status'])->toBe('waiting')
        ->and($presented['waits'][0]['outcome'])->toBe('none')
        ->and($presented['signals'][0]['name'])->toBe('approved')
        ->and($presented['signals'][0]['payload'])->toBe('{"by":"bob"}')
        ->and($presented['progress'][0]['step_index'])->toBe('2')
        ->and($presented['progress'][0]['progress'])->toBe('{"pct":50}');
});

test('the presenter maps labels to a display map', function () {
    $detail = new DurableRunDetail(
        runId: 'r1',
        run: [],
        labels: ['env' => 'prod', 'attempt' => 3, 'flag' => true],
    );

    expect(DurableRunPresenter::present($detail)['labels'])->toBe([
        'env' => 'prod',
        'attempt' => '3',
        'flag' => 'true',
    ]);
});

test('the presenter reuses the run-history sub-shape when history is present, null otherwise', function () {
    $withHistory = DurableRunPresenter::present(new DurableRunDetail(
        runId: 'r1',
        run: [],
        history: [
            'run_id' => 'r1', 'swarm_class' => 'App\\Swarms\\Example', 'topology' => 'hierarchical', 'status' => 'completed',
            'context' => ['input' => 'the prompt'], 'context_available' => true,
            'output' => 'the answer', 'output_available' => true,
            'steps' => [],
        ],
    ));

    expect($withHistory['history']['context'])->toBe('the prompt')
        ->and($withHistory['history']['output'])->toBe('the answer');

    expect(DurableRunPresenter::present(new DurableRunDetail(runId: 'r1', run: []))['history'])->toBeNull();
});

test('the presenter tolerates malformed list entries by skipping them', function () {
    $detail = new DurableRunDetail(
        runId: 'r1',
        run: [],
        branches: ['not-an-array', ['branch_id' => 'b1']],
    );

    // The scalar entry is skipped; the array entry is mapped.
    expect(DurableRunPresenter::present($detail)['branches'])->toHaveCount(1)
        ->and(DurableRunPresenter::present($detail)['branches'][0]['branch_id'])->toBe('b1');
});

test('the presenter tolerates a null run row without erroring', function () {
    $presented = DurableRunPresenter::present(new DurableRunDetail(runId: 'r1', run: null));

    expect($presented['summary']['status'])->toBe('none')
        ->and($presented['lifecycle']['attempts'])->toBe('none')
        ->and($presented['branches'])->toBe([]);
});

// ---------------------------------------------------------------------------
// SwarmDurableRunResource — read-only shape + config-driven navigation.
// ---------------------------------------------------------------------------

test('the durable resource is read-only: only index and view pages, no create/edit/delete', function () {
    expect(array_keys(SwarmDurableRunResource::getPages()))->toBe(['index', 'view'])
        ->and(SwarmDurableRunResource::getModel())->toBe(SwarmDurableRun::class);
});

test('the durable resource navigation group and sort are driven by config', function () {
    expect(SwarmDurableRunResource::getNavigationGroup())->toBe('Swarm')
        ->and(SwarmDurableRunResource::getNavigationSort())->toBeNull();

    config()->set('swarm-filament.navigation.group', 'Observability');
    config()->set('swarm-filament.navigation.sort', 4);
    expect(SwarmDurableRunResource::getNavigationGroup())->toBe('Observability')
        ->and(SwarmDurableRunResource::getNavigationSort())->toBe(4);

    config()->set('swarm-filament.navigation.sort', 'oops');
    expect(SwarmDurableRunResource::getNavigationSort())->toBeNull();
});

test('the durable swarm label shows the class basename, empty for a null class', function () {
    expect(SwarmDurableRunResource::swarmLabel('App\\Swarms\\Example'))->toBe('Example')
        ->and(SwarmDurableRunResource::swarmLabel('Flat'))->toBe('Flat')
        ->and(SwarmDurableRunResource::swarmLabel(null))->toBe('');
});

test('the durable status color maps every durable status to a Filament token', function () {
    expect(SwarmDurableRunResource::statusColor('completed'))->toBe('success')
        ->and(SwarmDurableRunResource::statusColor('failed'))->toBe('danger')
        ->and(SwarmDurableRunResource::statusColor('timed_out'))->toBe('danger')
        ->and(SwarmDurableRunResource::statusColor('running'))->toBe('info')
        ->and(SwarmDurableRunResource::statusColor('waiting'))->toBe('warning')
        ->and(SwarmDurableRunResource::statusColor('paused'))->toBe('warning')
        ->and(SwarmDurableRunResource::statusColor('cancelled'))->toBe('gray')
        ->and(SwarmDurableRunResource::statusColor('pending'))->toBe('gray')
        ->and(SwarmDurableRunResource::statusColor(null))->toBe('gray');
});

test('the durable resource is registered on the plugin panel', function () {
    $plugin = new SwarmFilamentPlugin;
    $panel = Panel::make()->id('probe');

    $plugin->register($panel);

    expect($panel->getResources())->toContain(SwarmDurableRunResource::class);
});

// ---------------------------------------------------------------------------
// ViewSwarmDurableRun::resolveDisplay — the null→404 guard, below the render.
// ---------------------------------------------------------------------------

test('the durable detail resolve throws not-found when the run is unknown', function () {
    // find() returns null for an unknown run → 404, never the inspector's 500 throw.
    ViewSwarmDurableRun::resolveDisplay(durableInspectorFake(null), 'vanished-run');
})->throws(ModelNotFoundException::class);

test('the durable detail resolve maps the assembled detail through the presenter when present', function () {
    $inspector = durableInspectorFake(
        run: ['status' => 'completed'],
        detail: new DurableRunDetail(
            runId: 'r9',
            run: ['swarm_class' => 'App\\Swarms\\Example', 'status' => 'completed'],
            hierarchicalNodeOutputs: [['node_id' => 'n', 'output' => 'the output', 'output_available' => true]],
        ),
    );

    $presented = ViewSwarmDurableRun::resolveDisplay($inspector, 'r9');

    expect($presented['run_id'])->toBe('r9')
        ->and($presented['summary']['swarm_class'])->toBe('App\\Swarms\\Example')
        ->and($presented['node_outputs'][0]['output'])->toBe('the output');
});

// ---------------------------------------------------------------------------
// End-to-end data path: the real InspectsDurableRuns contract → presenter degrade.
// ---------------------------------------------------------------------------

test('a durable detail sourced through the real contract degrades poison seams and never leaks ciphertext', function () {
    config()->set('swarm.persistence.driver', 'database');
    config()->set('swarm.persistence.encrypt_at_rest', true);
    config()->set('swarm.persistence.decrypt_failure_policy', 'throw');
    app()->forgetInstance(SwarmPersistenceCipher::class);
    Artisan::call('migrate', ['--database' => 'testing']);

    $cipher = app(SwarmPersistenceCipher::class);
    /** @var DatabaseDurableRunStore $store */
    $store = app(DatabaseDurableRunStore::class);
    $runId = 'durable-inspect-1';
    $now = now('UTC');

    durableInspectorSeedRun($runId);

    // A clean node output (app key) and a poison one (foreign key).
    $store->storeHierarchicalNodeOutput($runId, 'node-a', 'clean node output', 3600);
    DB::table('swarm_durable_node_outputs')->insert([
        'run_id' => $runId, 'node_id' => 'node-b', 'output' => durableInspectorPoison('poison node output'),
        'expires_at' => $now->copy()->addHour(), 'created_at' => $now, 'updated_at' => $now,
    ]);

    // A branch with a clean sealed input, and a branch with a poison input+output.
    DB::table('swarm_durable_branches')->insert([
        [
            'run_id' => $runId, 'branch_id' => 'branch-clean', 'step_index' => 1, 'node_id' => 'fork',
            'agent_class' => 'Writer', 'parent_node_id' => null, 'status' => 'completed',
            'input' => $cipher->seal('clean branch input'), 'output' => $cipher->seal('clean branch output'),
            'usage' => null, 'metadata' => json_encode([]), 'failure' => null, 'duration_ms' => 12,
            'execution_token' => null, 'lease_acquired_at' => null, 'leased_until' => null, 'attempts' => 1,
            'queue_connection' => null, 'queue_name' => null, 'started_at' => $now, 'finished_at' => $now,
            'expires_at' => $now->copy()->addHour(), 'created_at' => $now, 'updated_at' => $now,
        ],
        [
            'run_id' => $runId, 'branch_id' => 'branch-poison', 'step_index' => 1, 'node_id' => 'fork',
            'agent_class' => 'Reviewer', 'parent_node_id' => null, 'status' => 'completed',
            'input' => durableInspectorPoison('poison branch input'), 'output' => durableInspectorPoison('poison branch output'),
            'usage' => null, 'metadata' => json_encode([]), 'failure' => null, 'duration_ms' => 34,
            'execution_token' => null, 'lease_acquired_at' => null, 'leased_until' => null, 'attempts' => 1,
            'queue_connection' => null, 'queue_name' => null, 'started_at' => $now, 'finished_at' => $now,
            'expires_at' => $now->copy()->addHour(), 'created_at' => $now, 'updated_at' => $now,
        ],
    ]);

    // Plaintext side-table data: labels, a detail carrying a stray sw0: string, a wait, a signal.
    $store->updateLabels($runId, ['env' => 'prod', 'attempt' => 2]);
    $store->updateDetails($runId, ['note' => 'kept', 'leaky' => 'sw0:should-be-masked']);
    $store->createWait($runId, 'human-approval', 'needs sign-off', 3600);
    $store->recordSignal($runId, 'approved', ['by' => 'alice']);

    $presented = ViewSwarmDurableRun::resolveDisplay(app(InspectsDurableRuns::class), $runId);

    // Summary maps the plaintext run row.
    expect($presented['summary']['swarm_class'])->toBe('App\\Swarms\\Example')
        ->and($presented['summary']['status'])->toBe('completed')
        ->and($presented['summary']['execution_mode'])->toBe('durable')
        ->and($presented['lifecycle']['attempts'])->toBe('4');

    // Node outputs: clean value survives, poison degrades — keyed by node_id.
    $nodes = collect($presented['node_outputs'])->keyBy('node_id');
    expect($nodes['node-a']['output'])->toBe('clean node output')
        ->and($nodes['node-b']['output'])->toBe('unavailable');

    // Branches: clean input/output survive, poison degrades — never ciphertext.
    $branches = collect($presented['branches'])->keyBy('branch_id');
    expect($branches['branch-clean']['input'])->toBe('clean branch input')
        ->and($branches['branch-clean']['output'])->toBe('clean branch output')
        ->and($branches['branch-poison']['input'])->toBe('unavailable')
        ->and($branches['branch-poison']['output'])->toBe('unavailable');

    // Labels + details, with the stray sealed detail masked.
    expect($presented['labels'])->toMatchArray(['env' => 'prod', 'attempt' => '2'])
        ->and($presented['details']['note'])->toBe('kept')
        ->and($presented['details']['leaky'])->toBe('unavailable');

    // Waits + signals surfaced.
    expect(collect($presented['waits'])->pluck('name'))->toContain('human-approval')
        ->and(collect($presented['signals'])->pluck('name'))->toContain('approved');

    // The leak invariant, end to end: no sw0: ciphertext survives anywhere.
    expect(json_encode($presented))->not->toContain('sw0:');
});
