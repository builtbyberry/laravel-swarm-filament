<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarmFilament\Resources\SwarmRunResource\Pages\ViewSwarmRun;
use BuiltByBerry\LaravelSwarmFilament\Support\RunDisplayPresenter;

/*
 * Run failure + artifacts + metadata coverage (audit F20). findForDisplay returns
 * `error`, `artifacts`, and `metadata` that the run page never surfaced — a failed
 * run showed a red status with no reason and produced artifacts appeared nowhere.
 *
 * Exercised below the Livewire layer, real-contract data-path style: the mapping
 * through the pure RunDisplayPresenter, and the ViewSwarmRun::hasFailure() gate via
 * a direct reflection call — so neither trips the page-render harness. The
 * sealed-value invariant is asserted directly: no `sw0:` ciphertext ever escapes.
 */

/**
 * Invoke the private static ViewSwarmRun::hasFailure() without rendering the page.
 *
 * @param  array<string, mixed>  $data
 */
function hasFailureOf(array $data): bool
{
    return (new ReflectionMethod(ViewSwarmRun::class, 'hasFailure'))->invoke(null, $data);
}

// --- Run error: the "why" behind a red status -----------------------------

test('present maps a captured failure to its message and exception class', function () {
    $error = RunDisplayPresenter::present([
        'status' => 'failed',
        'error' => ['message' => 'Rate limit exceeded', 'class' => 'App\\Exceptions\\RateLimited'],
    ])['error'];

    expect($error)->toBe(['message' => 'Rate limit exceeded', 'class' => 'App\\Exceptions\\RateLimited']);
});

test('present keeps the class when the capture policy Skipped the failure message', function () {
    // Skip policy omits `message` entirely, keeping only the class.
    $error = RunDisplayPresenter::present([
        'error' => ['class' => 'App\\Exceptions\\Boom'],
    ])['error'];

    expect($error)->toBe(['message' => null, 'class' => 'App\\Exceptions\\Boom']);
});

test('present reports no error for a run that did not capture one', function () {
    expect(RunDisplayPresenter::present(['status' => 'completed'])['error'])->toBeNull()
        ->and(RunDisplayPresenter::present(['error' => []])['error'])->toBeNull();
});

test('a sealed failure message never renders raw — it degrades to null (leak-proof)', function () {
    $error = RunDisplayPresenter::present([
        'error' => ['message' => 'sw0:AAAAciphertext', 'class' => 'App\\Exceptions\\Boom'],
    ])['error'];

    expect($error['message'])->toBeNull()
        ->and($error['class'])->toBe('App\\Exceptions\\Boom');
    // Defense in depth: the ciphertext appears nowhere in the mapped record.
    expect(json_encode($error))->not->toContain('sw0:');
});

// --- Artifacts facet: presence + empty-state ------------------------------

test('present returns an empty artifact list for a run that produced none', function () {
    expect(RunDisplayPresenter::present([])['artifacts'])->toBe([])
        ->and(RunDisplayPresenter::present(['artifacts' => 'not-an-array'])['artifacts'])->toBe([]);
});

test('present maps captured artifacts to name, content, and producing agent', function () {
    $artifacts = RunDisplayPresenter::present([
        'artifacts' => [
            ['name' => 'summary.md', 'content' => '# Report', 'step_agent_class' => 'App\\Agents\\Writer'],
        ],
    ])['artifacts'];

    expect($artifacts)->toBe([
        ['name' => 'summary.md', 'content' => '# Report', 'step_agent_class' => 'App\\Agents\\Writer'],
    ]);
});

test('an unnamed artifact degrades to a placeholder name and non-array entries are skipped', function () {
    $artifacts = RunDisplayPresenter::present([
        'artifacts' => [
            ['content' => 'x'],
            'a-bare-string',
            42,
        ],
    ])['artifacts'];

    expect($artifacts)->toHaveCount(1)
        ->and($artifacts[0]['name'])->toBe('(unnamed)')
        ->and($artifacts[0]['content'])->toBe('x');
});

test('a sealed leaf in structured artifact content is masked while plaintext siblings survive', function () {
    $artifacts = RunDisplayPresenter::present([
        'artifacts' => [
            ['name' => 'blob', 'content' => ['keep' => 'visible', 'secret' => 'sw0:ciphertext']],
        ],
    ])['artifacts'];

    // Partial mask: the plaintext sibling renders, the sealed leaf does not.
    expect($artifacts[0]['content'])->toContain('visible')
        ->and($artifacts[0]['content'])->not->toContain('sw0:')
        ->and($artifacts[0]['content'])->not->toContain('ciphertext');
});

test('a bare-sealed artifact content degrades to unavailable, never raw ciphertext', function () {
    $artifacts = RunDisplayPresenter::present([
        'artifacts' => [['name' => 'blob', 'content' => 'sw0:ciphertext']],
    ])['artifacts'];

    expect($artifacts[0]['content'])->toBe('unavailable');
    expect(json_encode($artifacts))->not->toContain('sw0:');
});

// --- Run metadata: lineage, mode, tags ------------------------------------

test('present surfaces run metadata: parent run, execution mode, and joined tags', function () {
    $meta = RunDisplayPresenter::present([
        'metadata' => [
            'parent_run_id' => 'run-parent-1',
            'execution_mode' => 'durable',
            'tags' => ['nightly', 'billing'],
        ],
    ])['run_metadata'];

    expect($meta)->toBe([
        'parent_run_id' => 'run-parent-1',
        'execution_mode' => 'durable',
        'tags' => 'nightly, billing',
    ]);
});

test('run metadata degrades absent keys to null and an empty tag list to null', function () {
    expect(RunDisplayPresenter::present([])['run_metadata'])
        ->toBe(['parent_run_id' => null, 'execution_mode' => null, 'tags' => null]);

    expect(RunDisplayPresenter::present(['metadata' => ['tags' => []]])['run_metadata']['tags'])->toBeNull();
});

// --- The Failure section gate ---------------------------------------------

test('the Failure section shows for a captured error or a failed status, and hides otherwise', function () {
    // A captured error payload → show, whatever the status.
    expect(hasFailureOf(['error' => ['class' => 'X']]))->toBeTrue()
        // A failed status with no captured detail still shows (answers "why is this red").
        ->and(hasFailureOf(['status' => 'failed']))->toBeTrue()
        // A clean completed run → no Failure section.
        ->and(hasFailureOf(['status' => 'completed', 'error' => null]))->toBeFalse()
        ->and(hasFailureOf([]))->toBeFalse();
});
