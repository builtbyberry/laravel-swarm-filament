<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarmFilament\Models\SwarmRun;
use BuiltByBerry\LaravelSwarmFilament\Resources\SwarmRunResource;
use Illuminate\Support\Carbon;

/*
 * The runs-list column formatters. durationLabel/tokensLabel own boundary logic
 * (unfinished / sub-second / zero → em dash), and gist is a redaction-sensitive
 * path rendered per row — it must filter the display sentinels and never surface
 * redacted or undecryptable text into a list cell.
 */

test('durationLabel formats whole seconds and dashes the unfinished or sub-second', function () {
    $finished = new SwarmRun;
    $finished->created_at = Carbon::parse('2026-07-09 01:00:00');
    $finished->finished_at = Carbon::parse('2026-07-09 01:00:05');
    expect(SwarmRunResource::durationLabel($finished))->toBe('5s');

    $subSecond = new SwarmRun;
    $subSecond->created_at = Carbon::parse('2026-07-09 01:00:00');
    $subSecond->finished_at = Carbon::parse('2026-07-09 01:00:00');
    expect(SwarmRunResource::durationLabel($subSecond))->toBe('—');

    $unfinished = new SwarmRun;
    $unfinished->created_at = Carbon::parse('2026-07-09 01:00:00');
    expect(SwarmRunResource::durationLabel($unfinished))->toBe('—');
});

test('tokensLabel sums usage and dashes a scripted (zero-token) run', function () {
    $llm = new SwarmRun;
    $llm->usage = ['prompt_tokens' => 120, 'completion_tokens' => 80];
    expect(SwarmRunResource::tokensLabel($llm))->toBe('200');

    $scripted = new SwarmRun;
    $scripted->usage = ['prompt_tokens' => 0, 'completion_tokens' => 0];
    expect(SwarmRunResource::tokensLabel($scripted))->toBe('—');

    $none = new SwarmRun;
    $none->usage = [];
    expect(SwarmRunResource::tokensLabel($none))->toBe('—');
});

test('gist filters the display sentinels and clips, never leaking redacted text', function () {
    $gist = new ReflectionMethod(SwarmRunResource::class, 'gist');
    $gist->setAccessible(true);
    $call = fn (mixed $v): ?string => $gist->invoke(null, $v);

    // every "no legible value" state collapses to null (the caller shows the swarm name)
    expect($call('unavailable'))->toBeNull()
        ->and($call('none'))->toBeNull()
        ->and($call('[redacted]'))->toBeNull()
        ->and($call(''))->toBeNull()
        ->and($call(null))->toBeNull()
        // strips a leading "[Agent]" label and collapses whitespace
        ->and($call('[Coordinator]   route   plan'))->toBe('route plan')
        // clips a long line
        ->and($call(str_repeat('x', 200)))->toEndWith('...');
});
