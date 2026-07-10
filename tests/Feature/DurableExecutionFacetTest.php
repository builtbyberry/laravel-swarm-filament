<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Responses\DurableRunDetail;
use BuiltByBerry\LaravelSwarmFilament\Support\DurableExecutionPresenter;

/*
 * Durable execution facet coverage (audit F20). InspectsDurableRuns::inspect()
 * assembles a DurableRunDetail whose waits / signals / progress / retry / recovery
 * / timeout state the run page previously ignored (it read only route_plan +
 * completed_node_ids). This facet surfaces that state READ-ONLY.
 *
 * Real-contract data-path style: the mapping is exercised against an actual
 * DurableRunDetail (the same value object InspectsDurableRuns::inspect() returns),
 * below the Livewire render layer. The sealed-value invariant is asserted directly:
 * no `sw0:` ciphertext ever escapes a wait / signal / progress payload.
 */

/**
 * A DurableRunDetail with the given durable side-table state, mirroring the shape
 * DurableRunInspector::inspect() assembles from the store.
 *
 * @param  array<string, mixed>  $run
 * @param  array<int, array<string, mixed>>  $waits
 * @param  array<int, array<string, mixed>>  $signals
 * @param  array<int, array<string, mixed>>  $progress
 */
function durableDetail(array $run = [], array $waits = [], array $signals = [], array $progress = []): DurableRunDetail
{
    return new DurableRunDetail(
        runId: $run['run_id'] ?? 'run-1',
        run: $run,
        waits: $waits,
        signals: $signals,
        progress: $progress,
    );
}

// --- Facet gate: durable-only ---------------------------------------------

test('the facet is absent for a non-durable run (no durable detail resolved)', function () {
    expect(DurableExecutionPresenter::present(null))->toBeNull();
});

test('the facet is present for a durable run, even one with no waits/signals/progress', function () {
    $facet = DurableExecutionPresenter::present(durableDetail(['status' => 'running']));

    expect($facet)->not->toBeNull()
        ->and($facet['status'])->toBe('running')
        ->and($facet['retry_attempt'])->toBe(0)
        ->and($facet['recovery_count'])->toBe(0)
        ->and($facet['waits'])->toBe([])
        ->and($facet['signals'])->toBe([])
        ->and($facet['progress'])->toBe([]);
});

// --- Retry / recovery / timeout status (structural scalars) ----------------

test('the facet surfaces retry attempt and recovery counts as structural scalars', function () {
    $facet = DurableExecutionPresenter::present(durableDetail([
        'status' => 'recovering',
        'retry_attempt' => 2,
        'recovery_count' => 3,
    ]));

    expect($facet['retry_attempt'])->toBe(2)
        ->and($facet['recovery_count'])->toBe(3);
});

test('a stamped timed_out_at marks the run as timed out', function () {
    $facet = DurableExecutionPresenter::present(durableDetail([
        'status' => 'failed',
        'timed_out_at' => '2026-07-09 21:00:00',
        'timeout_at' => '2026-07-09 20:59:00',
    ]));

    expect($facet['timeout'])->toBe([
        'timed_out' => true,
        'timed_out_at' => '2026-07-09 21:00:00',
        'timeout_at' => '2026-07-09 20:59:00',
    ]);
});

test('a timed_out status marks the run as timed out even without a stamped timestamp', function () {
    $facet = DurableExecutionPresenter::present(durableDetail(['status' => 'timed_out']));

    expect($facet['timeout']['timed_out'])->toBeTrue()
        ->and($facet['timeout']['timed_out_at'])->toBeNull();
});

test('a running durable run reports it has not timed out', function () {
    $facet = DurableExecutionPresenter::present(durableDetail(['status' => 'running']));

    expect($facet['timeout']['timed_out'])->toBeFalse();
});

// --- Waits: what the run is blocked on -------------------------------------

test('waits surface the name, status, reason, timeout, and signal id', function () {
    $facet = DurableExecutionPresenter::present(durableDetail(waits: [
        ['name' => 'approval', 'status' => 'waiting', 'reason' => 'awaiting manager sign-off', 'timeout_at' => '2026-07-10 00:00:00', 'signal_id' => null, 'outcome' => null],
        ['name' => 'payment', 'status' => 'signalled', 'reason' => null, 'timeout_at' => null, 'signal_id' => 42, 'outcome' => ['status' => 'signalled', 'timed_out' => false]],
    ]));

    expect($facet['waits'])->toHaveCount(2)
        ->and($facet['waits'][0]['name'])->toBe('approval')
        ->and($facet['waits'][0]['status'])->toBe('waiting')
        ->and($facet['waits'][0]['reason'])->toBe('awaiting manager sign-off')
        ->and($facet['waits'][0]['timeout_at'])->toBe('2026-07-10 00:00:00')
        ->and($facet['waits'][0]['signal_id'])->toBeNull()
        ->and($facet['waits'][1]['signal_id'])->toBe(42)
        ->and($facet['waits'][1]['outcome'])->toContain('signalled');
});

test('non-array wait rows are skipped', function () {
    $facet = DurableExecutionPresenter::present(durableDetail(waits: [
        ['name' => 'ok', 'status' => 'waiting'],
        'a-bare-string',
        42,
    ]));

    expect($facet['waits'])->toHaveCount(1)
        ->and($facet['waits'][0]['name'])->toBe('ok');
});

// --- Signals: what the run received ----------------------------------------

test('signals surface the name, status, consumed timestamp, and payload', function () {
    $facet = DurableExecutionPresenter::present(durableDetail(signals: [
        ['name' => 'approve', 'status' => 'consumed', 'consumed_at' => '2026-07-09 21:05:00', 'payload' => ['decision' => 'yes']],
    ]));

    expect($facet['signals'])->toHaveCount(1)
        ->and($facet['signals'][0]['name'])->toBe('approve')
        ->and($facet['signals'][0]['status'])->toBe('consumed')
        ->and($facet['signals'][0]['consumed_at'])->toBe('2026-07-09 21:05:00')
        ->and($facet['signals'][0]['payload'])->toContain('yes');
});

// --- Progress: markers the run recorded ------------------------------------

test('progress markers surface the branch, step, agent, timestamp, and detail', function () {
    $facet = DurableExecutionPresenter::present(durableDetail(progress: [
        ['branch_id' => 'branch-a', 'step_index' => 3, 'agent_class' => 'App\\Agents\\Worker', 'last_progress_at' => '2026-07-09 21:06:00', 'progress' => ['pct' => 40]],
    ]));

    expect($facet['progress'])->toHaveCount(1)
        ->and($facet['progress'][0]['branch_id'])->toBe('branch-a')
        ->and($facet['progress'][0]['step_index'])->toBe(3)
        ->and($facet['progress'][0]['agent_class'])->toBe('App\\Agents\\Worker')
        ->and($facet['progress'][0]['last_progress_at'])->toBe('2026-07-09 21:06:00')
        ->and($facet['progress'][0]['detail'])->toContain('40');
});

// --- Sealed-value invariant: no ciphertext escapes any payload -------------

test('a sealed leaf in a wait outcome is masked while plaintext siblings survive', function () {
    $facet = DurableExecutionPresenter::present(durableDetail(waits: [
        ['name' => 'w', 'status' => 'signalled', 'outcome' => ['keep' => 'visible', 'secret' => 'sw0:ciphertext']],
    ]));

    expect($facet['waits'][0]['outcome'])->toContain('visible')
        ->and($facet['waits'][0]['outcome'])->not->toContain('sw0:')
        ->and($facet['waits'][0]['outcome'])->not->toContain('ciphertext');
    expect(json_encode($facet))->not->toContain('sw0:');
});

test('a bare-sealed signal payload degrades to unavailable, never raw ciphertext', function () {
    $facet = DurableExecutionPresenter::present(durableDetail(signals: [
        ['name' => 's', 'status' => 'pending', 'payload' => 'sw0:ciphertext'],
    ]));

    expect($facet['signals'][0]['payload'])->toBe('unavailable');
    expect(json_encode($facet))->not->toContain('sw0:');
});

test('a sealed leaf in a progress detail is masked while plaintext siblings survive', function () {
    $facet = DurableExecutionPresenter::present(durableDetail(progress: [
        ['branch_id' => null, 'step_index' => 0, 'agent_class' => 'App\\W', 'progress' => ['note' => 'ok', 'token' => 'sw0:leak']],
    ]));

    expect($facet['progress'][0]['detail'])->toContain('ok')
        ->and($facet['progress'][0]['detail'])->not->toContain('sw0:')
        ->and($facet['progress'][0]['detail'])->not->toContain('leak');
    expect(json_encode($facet))->not->toContain('sw0:');
});

// --- Absent payloads degrade cleanly ---------------------------------------

test('absent wait/signal/progress payloads render as the none sentinel, not raw null', function () {
    $facet = DurableExecutionPresenter::present(durableDetail(
        waits: [['name' => 'w', 'status' => 'waiting']],
        signals: [['name' => 's', 'status' => 'pending']],
        progress: [['step_index' => 0]],
    ));

    expect($facet['waits'][0]['reason'])->toBe('none')
        ->and($facet['waits'][0]['outcome'])->toBe('none')
        ->and($facet['signals'][0]['payload'])->toBe('none')
        ->and($facet['progress'][0]['detail'])->toBe('none');
});
