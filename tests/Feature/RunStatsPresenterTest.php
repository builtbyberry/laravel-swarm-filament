<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarmFilament\Support\RunStatsPresenter;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

// Core's run-history table is only migrated on demand (persistence defaults to the
// DB-free cache driver), so build it fresh for each aggregate test.
beforeEach(fn () => Artisan::call('migrate', ['--database' => 'testing']));

/**
 * Insert a plaintext run-history row (the columns the index model reads), with
 * safe defaults for the sealed/operational columns the strip never touches.
 *
 * @param  array<string, mixed>  $overrides
 */
function seedStatsRun(array $overrides = []): void
{
    $now = now('UTC');

    DB::table('swarm_run_histories')->insert(array_merge([
        'run_id' => 'r-'.bin2hex(random_bytes(6)),
        'swarm_class' => 'App\\Swarms\\Demo',
        'topology' => 'sequential',
        'status' => 'completed',
        'context' => json_encode([]),
        'metadata' => json_encode([]),
        'steps' => json_encode([]),
        'output' => '',
        'usage' => json_encode([]),
        'error' => null,
        'artifacts' => json_encode([]),
        'finished_at' => $now,
        'expires_at' => $now->copy()->addHour(),
        'execution_token' => null,
        'leased_until' => null,
        'created_at' => $now,
        'updated_at' => $now,
    ], $overrides));
}

it('aggregates volume, attention, recent latency and tokens from plaintext columns only', function () {
    $base = now('UTC');

    // Finished durations: 10s, 20s, 30s, 5s. The failed run never finished.
    seedStatsRun(['run_id' => 'a', 'status' => 'completed', 'created_at' => $base->copy()->subSeconds(10), 'finished_at' => $base, 'usage' => json_encode(['prompt_tokens' => 60, 'completion_tokens' => 40])]);
    seedStatsRun(['run_id' => 'b', 'status' => 'completed', 'created_at' => $base->copy()->subSeconds(20), 'finished_at' => $base, 'usage' => json_encode(['prompt_tokens' => 150, 'completion_tokens' => 50])]);
    seedStatsRun(['run_id' => 'c', 'status' => 'completed', 'created_at' => $base->copy()->subSeconds(30), 'finished_at' => $base, 'usage' => json_encode([])]);
    seedStatsRun(['run_id' => 'd', 'status' => 'dead_letter', 'created_at' => $base->copy()->subSeconds(5), 'finished_at' => $base, 'usage' => json_encode(['prompt_tokens' => 10, 'completion_tokens' => 0])]);
    seedStatsRun(['run_id' => 'e', 'status' => 'failed', 'created_at' => $base->copy()->subSeconds(3), 'finished_at' => null, 'usage' => json_encode(['prompt_tokens' => 5, 'completion_tokens' => 5])]);

    $stats = RunStatsPresenter::present();

    expect($stats['total'])->toBe(5)
        ->and($stats['needs_attention'])->toBe(2)      // dead_letter + failed
        ->and($stats['window'])->toBe(5)
        ->and($stats['tokens'])->toBe(320)             // 100 + 200 + 0 + 10 + 10
        ->and($stats['p50_latency'])->toBe('20s');     // sorted [5,10,20,30] → median idx 2
});

it('returns null latency when nothing has finished, and zeros on an empty table', function () {
    expect(RunStatsPresenter::present())->toMatchArray([
        'total' => 0,
        'needs_attention' => 0,
        'p50_latency' => null,
        'tokens' => 0,
        'window' => 0,
    ]);

    seedStatsRun(['run_id' => 'x', 'status' => 'running', 'finished_at' => null, 'usage' => json_encode(['prompt_tokens' => 7])]);

    $stats = RunStatsPresenter::present();

    expect($stats['total'])->toBe(1)
        ->and($stats['p50_latency'])->toBeNull()       // no finished run in the window
        ->and($stats['tokens'])->toBe(7);
});

it('never counts a sealed column — attention tracks only the failure family', function () {
    seedStatsRun(['run_id' => 'ok', 'status' => 'completed']);
    seedStatsRun(['run_id' => 'run', 'status' => 'running', 'finished_at' => null]);
    seedStatsRun(['run_id' => 'to', 'status' => 'timed_out', 'finished_at' => null]);

    $stats = RunStatsPresenter::present();

    expect($stats['total'])->toBe(3)
        ->and($stats['needs_attention'])->toBe(1);     // only timed_out
});

it('formats human durations compactly', function () {
    expect(RunStatsPresenter::humanizeDuration(45))->toBe('45s')
        ->and(RunStatsPresenter::humanizeDuration(90))->toBe('1m 30s')
        ->and(RunStatsPresenter::humanizeDuration(3720))->toBe('1h 2m')
        ->and(RunStatsPresenter::humanizeDuration(-4))->toBe('0s');
});
