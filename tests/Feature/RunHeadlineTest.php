<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarmFilament\Resources\SwarmRunResource\Pages\ViewSwarmRun;
use BuiltByBerry\LaravelSwarmFilament\Support\RunDisplayPresenter;

/*
 * Run headline coverage (audit F18). The headline is the run's plain-language
 * "so what" line: the RunDisplayPresenter *metrics* it is built from, and the
 * ViewSwarmRun::headline() string assembly that renders them. Both are exercised
 * below the Livewire layer — the metrics through the pure presenter, the assembly
 * through a direct (reflection) call to the private static, so neither trips the
 * page-render harness.
 */

/**
 * Invoke the private static ViewSwarmRun::headline() without rendering the page.
 *
 * @param  array<string, mixed>  $data
 */
function headlineOf(array $data): string
{
    // Private statics are reflection-invokable without setAccessible() on PHP 8.1+.
    return (new ReflectionMethod(ViewSwarmRun::class, 'headline'))->invoke(null, $data);
}

// --- Headline metrics: token totals ---------------------------------------

test('headline metrics sum prompt- and completion-only usage and ignore non-numeric tokens', function () {
    expect(RunDisplayPresenter::present(['usage' => ['prompt_tokens' => 30]])['metrics']['tokens'])->toBe(30)
        ->and(RunDisplayPresenter::present(['usage' => ['completion_tokens' => 20]])['metrics']['tokens'])->toBe(20)
        ->and(RunDisplayPresenter::present(['usage' => ['prompt_tokens' => 'abc', 'completion_tokens' => 5]])['metrics']['tokens'])->toBe(5);
});

// --- Headline metrics: duration edge cases --------------------------------

test('headline metrics report duration only for a real, positive elapsed window', function () {
    $duration = fn (mixed $start, mixed $end): ?string => RunDisplayPresenter::present([
        'started_at' => $start,
        'finished_at' => $end,
    ])['metrics']['duration'];

    // A real, forward window is reported in seconds.
    expect($duration('2026-07-09T10:00:00Z', '2026-07-09T10:00:03Z'))->toBe('3s')
        // Equal timestamps → zero elapsed → nothing to report (not "0s" noise).
        ->and($duration('2026-07-09T10:00:00Z', '2026-07-09T10:00:00Z'))->toBeNull()
        // A finished-before-started window is nonsense → suppressed, not negative.
        ->and($duration('2026-07-09T10:00:03Z', '2026-07-09T10:00:00Z'))->toBeNull()
        // Non-string timestamps (e.g. a not-yet-finished run) → no duration.
        ->and($duration('2026-07-09T10:00:00Z', null))->toBeNull()
        ->and($duration(123, 456))->toBeNull();
});

// --- Headline assembly: the "so what" line --------------------------------

test('headline assembles the full so-what line: swarm, status, topology, steps, duration, tokens', function () {
    $headline = headlineOf([
        'swarm_class' => 'App\\Swarms\\Example',
        'status' => 'completed',
        'topology' => 'sequential',
        'metrics' => ['steps' => 2, 'duration' => '3s', 'tokens' => 1500],
    ]);

    expect($headline)->toBe('Example · Completed · sequential · 2 steps · 3s · 1,500 tokens');
});

test('headline pluralizes steps and drops absent parts, falling back to "Run" without a swarm class', function () {
    // One step → singular; no swarm/status/topology/duration/tokens → those parts drop.
    expect(headlineOf(['metrics' => ['steps' => 1]]))->toBe('Run · 1 step')
        // Zero steps stays plural.
        ->and(headlineOf(['metrics' => ['steps' => 0]]))->toBe('Run · 0 steps');
});
