<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Contracts\ReadableRunHistoryStore;
use BuiltByBerry\LaravelSwarmFilament\Resources\SwarmRunResource;
use BuiltByBerry\LaravelSwarmFilament\Support\RunSummaryMemo;
use BuiltByBerry\LaravelSwarmFilament\SwarmFilamentServiceProvider;
use Illuminate\Container\Container;

/*
 * Component (audit F2) — request-scope the runs-list summary memo.
 *
 * The runs index memoizes run-id → summary so each visible row resolves its
 * display record at most once per render. That memo MUST be request-scoped: a
 * `static` cache would, under Octane, (1) grow unbounded per worker across every
 * run ever listed and (2) serve a stale null forever for a run whose display
 * record was absent at first view.
 *
 * These tests drive the exact container primitives Octane uses — a `scoped()`
 * binding flushed by `forgetScopedInstances()` on every `RequestReceived` — on a
 * real container we own, rather than through a Livewire/HTTP page render (which
 * hits the Filament v5 + Testbench getErrorBag() harness bug). The container
 * wiring IS the surface under test, so it is exercised directly and
 * deterministically.
 */

/**
 * A ReadableRunHistoryStore whose findForDisplay() return value can be flipped
 * between simulated requests, exactly as a run's persisted record could appear
 * later (a running run finishing, an expired record repopulated, etc.). It also
 * counts calls so a test can prove a later request re-resolved rather than
 * serving a memoized value.
 */
function memoMutableStore(): ReadableRunHistoryStore
{
    return new class implements ReadableRunHistoryStore
    {
        /** @var ?array<string, mixed> */
        public ?array $display = null;

        public int $calls = 0;

        public function findForDisplay(string $runId): ?array
        {
            $this->calls++;

            return $this->display;
        }

        public function query(?string $swarmClass = null, ?string $status = null, int $limit = 25): array
        {
            return [];
        }
    };
}

/**
 * A fresh container wired the way the package provider wires the app: the
 * RunSummaryMemo bound as a request-scoped instance (the real registration under
 * test), installed as the global container so `SwarmRunResource::runSummary()`'s
 * `app()` calls resolve against it.
 */
function memoBootContainer(ReadableRunHistoryStore $store): Container
{
    $container = new Container;
    Container::setInstance($container);

    // Register the binding through the ACTUAL provider hook, so the test fails if
    // the provider stops binding the memo (or binds it as a singleton/static).
    (new SwarmFilamentServiceProvider($container))->packageRegistered();

    $container->instance(ReadableRunHistoryStore::class, $store);

    return $container;
}

/**
 * Simulate the request boundary Laravel/Octane crosses on RequestReceived: it
 * flushes every `scoped()` instance so the next request re-resolves a fresh one.
 */
function memoEndRequest(Container $container): void
{
    $container->forgetScopedInstances();
}

afterEach(function () {
    // Leave the global container as we found it for any Orchestra-backed tests.
    Container::setInstance(null);
});

test('the provider binds the memo request-scoped: one instance per request, fresh after the boundary', function () {
    $container = memoBootContainer(memoMutableStore());

    $first = $container->make(RunSummaryMemo::class);

    // Within one request the scoped binding hands back the very same instance,
    // so a row resolved once is not re-resolved for the rest of the render.
    expect($container->make(RunSummaryMemo::class))->toBe($first);

    memoEndRequest($container);

    // The next request gets a brand-new memo — never the previous request's.
    expect($container->make(RunSummaryMemo::class))->not->toBe($first);
});

test('a null summary from an earlier request is NOT served stale in a later request', function () {
    $store = memoMutableStore();
    $container = memoBootContainer($store);

    // Request 1: the display record is absent → summary resolves to null and is
    // memoized as a hit (correct within this request; findForDisplay is
    // deterministic here).
    $store->display = null;
    expect(SwarmRunResource::runSummary('run-1'))->toBeNull();
    // A second read in the SAME request is served from the memo — no re-resolve.
    expect(SwarmRunResource::runSummary('run-1'))->toBeNull();
    expect($store->calls)->toBe(1);

    // The request ends; Octane flushes scoped instances.
    memoEndRequest($container);

    // Request 2: the same run's display record is now present. The memo must
    // re-resolve rather than serving request 1's stale null — the exact defect a
    // static cache carries for a worker's whole life.
    $store->display = [
        'run_id' => 'run-1', 'swarm_class' => 'App\\Swarms\\Demo', 'topology' => 'sequential', 'status' => 'completed',
        'context' => ['input' => 'summarize the quarterly report'], 'context_available' => true,
        'output' => 'done', 'output_available' => true,
        'steps' => [],
    ];

    expect(SwarmRunResource::runSummary('run-1'))->toBe('summarize the quarterly report');
    // The second request did re-resolve through the contract (call count grew).
    expect($store->calls)->toBe(2);
});

test('the memo is bounded to a single render and does not accumulate across requests', function () {
    $store = memoMutableStore();
    $container = memoBootContainer($store);
    $store->display = null;

    // Request 1: render a page of rows — each run id is memoized once.
    foreach (['a', 'b', 'c'] as $runId) {
        SwarmRunResource::runSummary($runId);
    }

    expect($container->make(RunSummaryMemo::class)->count())->toBe(3);

    memoEndRequest($container);

    // Request 2: a fresh memo starts empty — the previous request's entries do
    // not carry over, so the memo cannot grow unbounded across a worker's life.
    expect($container->make(RunSummaryMemo::class)->count())->toBe(0);

    SwarmRunResource::runSummary('d');
    expect($container->make(RunSummaryMemo::class)->count())->toBe(1);
});

test('the memo remembers a resolved value and only computes it once per request', function () {
    $memo = new RunSummaryMemo;
    $calls = 0;

    $resolve = function () use (&$calls): ?string {
        $calls++;

        return 'the summary';
    };

    expect($memo->remember('r', $resolve))->toBe('the summary')
        ->and($memo->remember('r', $resolve))->toBe('the summary')
        ->and($calls)->toBe(1)
        ->and($memo->count())->toBe(1);
});

test('the memo treats a resolved null as a hit within the request (null-as-hit is kept)', function () {
    $memo = new RunSummaryMemo;
    $calls = 0;

    $resolve = function () use (&$calls): ?string {
        $calls++;

        return null;
    };

    expect($memo->remember('r', $resolve))->toBeNull()
        ->and($memo->remember('r', $resolve))->toBeNull()
        // A cached null is a hit, not a re-resolve — deterministic within a request.
        ->and($calls)->toBe(1);
});
