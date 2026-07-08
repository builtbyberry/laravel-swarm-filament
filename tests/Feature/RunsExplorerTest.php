<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Contracts\ReadableRunHistoryStore;
use BuiltByBerry\LaravelSwarm\Persistence\SwarmPersistenceCipher;
use BuiltByBerry\LaravelSwarmFilament\Models\SwarmRun;
use BuiltByBerry\LaravelSwarmFilament\Resources\SwarmRunResource;
use BuiltByBerry\LaravelSwarmFilament\Support\RunDisplayPresenter;
use Illuminate\Config\Repository;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Psr\Log\NullLogger;

/*
 * Component #4 — the runs explorer (index + run/step detail). The index binds the
 * plaintext SwarmRun model; the detail sources sealed fields ONLY through the
 * v0.19 ReadableRunHistoryStore::findForDisplay() contract, mapped by
 * RunDisplayPresenter so no sw0: ciphertext can ever reach a rendered cell.
 */

/** A sealed sw0: value under a foreign key — undecryptable here, so it degrades. */
function runsExplorerPoison(string $plaintext = 'unreadable'): string
{
    $foreign = new SwarmPersistenceCipher(
        new Repository(['swarm.persistence.encrypt_at_rest' => true, 'swarm.persistence.driver' => 'database']),
        new Encrypter(random_bytes(32), 'aes-256-cbc'),
        new NullLogger,
    );

    return (string) $foreign->seal($plaintext);
}

// ---------------------------------------------------------------------------
// RunDisplayPresenter — the single leak-proof mapping, tested in isolation.
// ---------------------------------------------------------------------------

test('the presenter renders an available sealed field as its decrypted value', function () {
    $presented = RunDisplayPresenter::present([
        'run_id' => 'r1', 'swarm_class' => 'App\\Swarms\\Demo', 'topology' => 'sequential', 'status' => 'completed',
        'context' => ['input' => 'the prompt'], 'context_available' => true,
        'output' => 'the answer', 'output_available' => true,
        'steps' => [],
    ]);

    expect($presented['run_id'])->toBe('r1')
        ->and($presented['swarm_class'])->toBe('App\\Swarms\\Demo')
        ->and($presented['status'])->toBe('completed')
        ->and($presented['context'])->toBe('the prompt')
        ->and($presented['output'])->toBe('the answer');
});

test('the presenter degrades an undecryptable field to unavailable, never leaking ciphertext', function () {
    $presented = RunDisplayPresenter::present([
        'context' => ['input' => null], 'context_available' => false,
        'output' => null, 'output_available' => false,
        'steps' => [],
    ]);

    expect($presented['context'])->toBe('unavailable')
        ->and($presented['output'])->toBe('unavailable');
});

test('the presenter distinguishes an empty/absent field (none) from an undecryptable one (unavailable)', function () {
    $presented = RunDisplayPresenter::present([
        // available flag true but nothing stored — a running or skipped field.
        'context' => ['input' => null], 'context_available' => true,
        'output' => null, 'output_available' => true,
        'steps' => [],
    ]);

    expect($presented['context'])->toBe('none')
        ->and($presented['output'])->toBe('none');
});

test('the presenter masks a value that still looks like sw0: ciphertext even if flagged available', function () {
    // Defense in depth: an upstream read that wrongly flags a sealed value
    // available must still never surface the ciphertext.
    $presented = RunDisplayPresenter::present([
        'output' => 'sw0:leaked', 'output_available' => true,
        'steps' => [],
    ]);

    expect($presented['output'])->toBe('unavailable');
});

test('the presenter maps the step timeline and degrades per step field', function () {
    $presented = RunDisplayPresenter::present([
        'steps' => [
            ['step_index' => 0, 'agent_class' => 'Writer', 'input' => 'draft', 'input_available' => true, 'output' => null, 'output_available' => false],
            // A skipped step field: the key is absent entirely.
            ['step_index' => 1, 'agent_class' => 'Reviewer'],
        ],
    ]);

    expect($presented['steps'])->toHaveCount(2)
        ->and($presented['steps'][0])->toBe(['step_index' => 0, 'agent_class' => 'Writer', 'input' => 'draft', 'output' => 'unavailable'])
        ->and($presented['steps'][1])->toBe(['step_index' => 1, 'agent_class' => 'Reviewer', 'input' => 'none', 'output' => 'none']);
});

test('the presenter tolerates a malformed steps value', function () {
    expect(RunDisplayPresenter::present(['steps' => 'not-an-array'])['steps'])->toBe([])
        ->and(RunDisplayPresenter::present([])['steps'])->toBe([]);
});

// ---------------------------------------------------------------------------
// SwarmRunResource — read-only shape + config-driven navigation.
// ---------------------------------------------------------------------------

test('the resource is read-only: only index and view pages, no create/edit/delete', function () {
    expect(array_keys(SwarmRunResource::getPages()))->toBe(['index', 'view'])
        ->and(SwarmRunResource::getModel())->toBe(SwarmRun::class);
});

test('the resource navigation group is driven by config', function () {
    expect(SwarmRunResource::getNavigationGroup())->toBe('Swarm');

    config()->set('swarm-filament.navigation.group', 'Observability');
    expect(SwarmRunResource::getNavigationGroup())->toBe('Observability');
});

test('the shared status color maps every run status to a Filament token', function () {
    expect(SwarmRunResource::statusColor('completed'))->toBe('success')
        ->and(SwarmRunResource::statusColor('failed'))->toBe('danger')
        ->and(SwarmRunResource::statusColor('running'))->toBe('warning')
        ->and(SwarmRunResource::statusColor('anything-else'))->toBe('gray')
        ->and(SwarmRunResource::statusColor(null))->toBe('gray');
});

// ---------------------------------------------------------------------------
// End-to-end data path: the real display contract → presenter degrade.
// ---------------------------------------------------------------------------

test('a run detail sourced through findForDisplay degrades poison fields and keeps clean ones', function () {
    config()->set('swarm.persistence.driver', 'database');
    config()->set('swarm.persistence.encrypt_at_rest', true);
    config()->set('swarm.persistence.decrypt_failure_policy', 'throw');
    app()->forgetInstance(SwarmPersistenceCipher::class);
    Artisan::call('migrate', ['--database' => 'testing']);

    $cipher = app(SwarmPersistenceCipher::class);
    $runId = 'runs-explorer-1';
    $now = now('UTC');

    DB::table('swarm_run_histories')->insert([
        'run_id' => $runId,
        'swarm_class' => 'App\\Swarms\\Example',
        'topology' => 'sequential',
        'status' => 'completed',
        'context' => json_encode(['input' => runsExplorerPoison('ctx')]),
        'metadata' => json_encode([]),
        'steps' => json_encode([[
            'step_index' => 0,
            'agent_class' => 'Writer',
            'input' => $cipher->seal('clean step input'),
            'output' => runsExplorerPoison('poison step output'),
        ]]),
        'output' => $cipher->seal('clean run output'),
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

    $detail = app(ReadableRunHistoryStore::class)->findForDisplay($runId);
    $presented = RunDisplayPresenter::present($detail);

    expect($presented['status'])->toBe('completed')
        // Poison context input degrades; clean run output survives.
        ->and($presented['context'])->toBe('unavailable')
        ->and($presented['output'])->toBe('clean run output')
        // Step: clean input rendered, poison output degraded — never ciphertext.
        ->and($presented['steps'][0]['input'])->toBe('clean step input')
        ->and($presented['steps'][0]['output'])->toBe('unavailable')
        ->and($presented['steps'][0]['agent_class'])->toBe('Writer');

    // No sw0: ciphertext survives anywhere in the presented record.
    expect(json_encode($presented))->not->toContain('sw0:');
});
