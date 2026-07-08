<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarmFilament\Models\SwarmRun;
use BuiltByBerry\LaravelSwarmFilament\Support\DisplayField;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/*
 * Component #2 — read adapters over the v0.19 display contracts. The read layer
 * exposes run data for display without ever surfacing sealed sw0: ciphertext or
 * mutating core's tables. Records 629/632.
 */

function readAdaptersSeedRun(string $runId, string $status = 'completed'): void
{
    config()->set('swarm.persistence.driver', 'database');
    Artisan::call('migrate', ['--database' => 'testing']);

    $now = now('UTC');

    DB::table('swarm_run_histories')->insert([
        'run_id' => $runId,
        'swarm_class' => 'App\\Swarms\\Example',
        'topology' => 'sequential',
        'status' => $status,
        // Sealed-looking payload columns — the model must never select these.
        'context' => json_encode(['input' => 'sw0:sealed-context']),
        'metadata' => json_encode([]),
        'steps' => json_encode([]),
        'output' => 'sw0:sealed-output',
        'usage' => json_encode([]),
        'error' => null,
        'artifacts' => json_encode([]),
        'finished_at' => $now,
        'expires_at' => $now->copy()->addHour(),
        'execution_token' => 'operational-token',
        'leased_until' => null,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
}

test('SwarmRun selects only plaintext columns and never loads sealed or operational ones', function () {
    readAdaptersSeedRun('run-1');

    $run = SwarmRun::query()->first();

    expect($run)->not->toBeNull()
        ->and($run->run_id)->toBe('run-1')
        ->and($run->swarm_class)->toBe('App\\Swarms\\Example')
        ->and($run->topology)->toBe('sequential')
        ->and($run->status)->toBe('completed');

    // The sealed columns (context/output/steps) and operational columns are never
    // selected → never present in the attribute bag → cannot leak into a table cell.
    $attributes = $run->getAttributes();
    expect($attributes)->not->toHaveKey('context')
        ->and($attributes)->not->toHaveKey('output')
        ->and($attributes)->not->toHaveKey('steps')
        ->and($attributes)->not->toHaveKey('execution_token')
        ->and($attributes)->not->toHaveKey('leased_until');
});

test('SwarmRun filters and sorts natively on plaintext columns', function () {
    readAdaptersSeedRun('run-done', 'completed');
    readAdaptersSeedRun('run-failed', 'failed');

    expect(SwarmRun::query()->where('status', 'failed')->pluck('run_id')->all())->toBe(['run-failed'])
        ->and(SwarmRun::query()->orderBy('run_id')->pluck('run_id')->all())->toBe(['run-done', 'run-failed']);
});

test('SwarmRun is read-only — writes and deletes fail loud', function () {
    readAdaptersSeedRun('run-1');

    $new = new SwarmRun;
    $new->setAttribute('run_id', 'x');
    $new->setAttribute('status', 'completed');
    expect(fn () => $new->save())->toThrow(LogicException::class, 'read-only');

    $existing = SwarmRun::query()->first();
    expect(fn () => $existing->delete())->toThrow(LogicException::class, 'read-only');

    // Nothing was written.
    expect(DB::table('swarm_run_histories')->count())->toBe(1);
});

test('DisplayField renders an available value', function () {
    $field = DisplayField::fromRow(['input' => 'the prompt', 'input_available' => true], 'input');

    expect($field->isAvailable())->toBeTrue()
        ->and($field->display())->toBe('the prompt');
});

test('DisplayField renders a placeholder for an unavailable field', function () {
    $field = DisplayField::fromRow(['input' => null, 'input_available' => false], 'input');

    expect($field->isAvailable())->toBeFalse()
        ->and($field->display())->toBe('unavailable')
        ->and($field->display('—'))->toBe('—');
});

test('DisplayField masks a still-sealed value even when the row flags it available', function () {
    // Defense in depth: never leak ciphertext regardless of the upstream flag.
    $field = DisplayField::fromRow(['input' => 'sw0:leaked-ciphertext', 'input_available' => true], 'input');

    expect($field->value)->toBeNull()
        ->and($field->isAvailable())->toBeFalse()
        ->and($field->display())->toBe('unavailable');
});

test('DisplayField treats an absent availability flag as available', function () {
    expect(DisplayField::fromRow(['status' => 'completed'], 'status')->display())->toBe('completed');
});

test('DisplayField stringifies non-string scalar values', function () {
    expect(DisplayField::fromRow(['n' => 42, 'n_available' => true], 'n')->display())->toBe('42')
        ->and(DisplayField::fromRow(['b' => false, 'b_available' => true], 'b')->display())->toBe('false');
});
