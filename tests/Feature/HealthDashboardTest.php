<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Contracts\InspectsDurableRuns;
use BuiltByBerry\LaravelSwarm\Contracts\ReadableAuditOutbox;
use BuiltByBerry\LaravelSwarm\Responses\DurableRunDetail;
use BuiltByBerry\LaravelSwarmFilament\Pages\SwarmHealthPage;
use BuiltByBerry\LaravelSwarmFilament\Pages\SwarmPage;
use BuiltByBerry\LaravelSwarmFilament\Support\HealthCheck;
use BuiltByBerry\LaravelSwarmFilament\Support\HealthStatus;
use BuiltByBerry\LaravelSwarmFilament\Support\SwarmHealthReport;
use BuiltByBerry\LaravelSwarmFilament\Widgets\SwarmHealthWidget;
use BuiltByBerry\LaravelSwarmFilament\Widgets\SwarmWidget;

/*
 * Component #8 — the read-only health dashboard. The swarm:health readiness signal
 * (durable + audit persistence reachability) surfaced as pass/fail/degraded,
 * consumed ONLY through the public read seams (InspectsDurableRuns +
 * ReadableAuditOutbox::healthSummary). The evaluator is pure, so it is unit-tested
 * here with scripted/throwing stubs — no Livewire render.
 */

/** A durable inspector stub whose reachability probe either answers or throws. */
function healthDurableStub(bool $reachable): InspectsDurableRuns
{
    return new class($reachable) implements InspectsDurableRuns
    {
        public function __construct(private bool $reachable) {}

        public function find(string $runId): ?array
        {
            if (! $this->reachable) {
                throw new RuntimeException('durable store unreachable');
            }

            // Reachable: the sentinel probe id is never a real run, so null.
            return null;
        }

        public function inspect(string $runId): DurableRunDetail
        {
            throw new RuntimeException('not used by the health probe');
        }

        public function inspectByLabels(array $labels, int $limit = 50): array
        {
            return [];
        }
    };
}

/**
 * A readable-audit-outbox stub whose healthSummary is scripted, or throws.
 *
 * @param  array<string, mixed>|Throwable  $summary
 */
function healthAuditStub(array|Throwable $summary): ReadableAuditOutbox
{
    return new class($summary) implements ReadableAuditOutbox
    {
        public function __construct(private array|Throwable $summary) {}

        public function isAvailable(): bool
        {
            return is_array($this->summary) && ($this->summary['available'] ?? false) === true;
        }

        public function pending(int $limit = 100): array
        {
            return [];
        }

        public function deadLettered(int $limit = 100): array
        {
            return [];
        }

        public function record(int $id): ?array
        {
            return null;
        }

        public function healthSummary(): array
        {
            if ($this->summary instanceof Throwable) {
                throw $this->summary;
            }

            return $this->summary;
        }
    };
}

/**
 * @return array<string, mixed>
 */
function healthAuditSummary(int $pending = 0, int $deadLetter = 0, bool $available = true): array
{
    return [
        'available' => $available,
        'pending' => $pending,
        'dead_letter' => $deadLetter,
        'reserved' => 0,
        'oldest_pending_at' => null,
    ];
}

/** @return array<string, HealthCheck> keyed by check key */
function healthChecksByKey(SwarmHealthReport $report): array
{
    $byKey = [];
    foreach ($report->checks as $check) {
        $byKey[$check->key] = $check;
    }

    return $byKey;
}

// ---------------------------------------------------------------------------
// SwarmHealthReport — the pure pass/fail/degraded evaluator.
// ---------------------------------------------------------------------------

test('both lanes reachable and clean → overall pass and ok', function () {
    $report = SwarmHealthReport::for(healthDurableStub(true), healthAuditStub(healthAuditSummary(pending: 2)));

    $checks = healthChecksByKey($report);

    expect($report->ok)->toBeTrue()
        ->and($report->status)->toBe(HealthStatus::Pass)
        ->and($report->checks)->toHaveCount(2)
        ->and($checks['durable']->status)->toBe(HealthStatus::Pass)
        ->and($checks['audit']->status)->toBe(HealthStatus::Pass)
        // The pending count is surfaced (a non-sensitive count, never a payload).
        ->and($checks['audit']->summary)->toContain('2 pending');
});

test('dead-letter rows fail the audit lane and flip overall ok', function () {
    $report = SwarmHealthReport::for(healthDurableStub(true), healthAuditStub(healthAuditSummary(deadLetter: 3)));

    $checks = healthChecksByKey($report);

    expect($report->ok)->toBeFalse()
        ->and($report->status)->toBe(HealthStatus::Fail)
        ->and($checks['audit']->status)->toBe(HealthStatus::Fail)
        ->and($checks['audit']->summary)->toContain('3 undelivered audit record(s)')
        // Durable is unaffected.
        ->and($checks['durable']->status)->toBe(HealthStatus::Pass);
});

test('an unreachable durable store degrades that lane without failing overall ok', function () {
    // Degraded ≠ hard fail: an app that simply does not run durable execution is
    // not reported "unhealthy" — the lane shows its own degraded badge only.
    $report = SwarmHealthReport::for(healthDurableStub(false), healthAuditStub(healthAuditSummary()));

    $checks = healthChecksByKey($report);

    expect($report->ok)->toBeTrue()
        ->and($report->status)->toBe(HealthStatus::Degraded)
        ->and($checks['durable']->status)->toBe(HealthStatus::Degraded)
        ->and($checks['durable']->summary)->toContain('may not be configured')
        // The raw thrown text must NOT leak into the summary — a read-only viewer
        // must never see schema/connection detail (HealthCheck's leak invariant).
        ->and($checks['durable']->summary)->not->toContain('unreachable');
});

test('an audit outbox with no persistent store is degraded, not failed', function () {
    $report = SwarmHealthReport::for(healthDurableStub(true), healthAuditStub(healthAuditSummary(available: false)));

    $checks = healthChecksByKey($report);

    expect($report->ok)->toBeTrue()
        ->and($report->status)->toBe(HealthStatus::Degraded)
        ->and($checks['audit']->status)->toBe(HealthStatus::Degraded)
        ->and($checks['audit']->summary)->toContain('not backed by a persistent store');
});

test('a throwing audit summary degrades rather than propagating — the surface never 500s', function () {
    $report = SwarmHealthReport::for(healthDurableStub(true), healthAuditStub(new RuntimeException('boom')));

    $checks = healthChecksByKey($report);

    expect($report->ok)->toBeTrue()
        ->and($checks['audit']->status)->toBe(HealthStatus::Degraded)
        ->and($checks['audit']->summary)->toBe('Audit outbox did not respond.');
});

test('a hard fail outranks a degraded lane in the overall status', function () {
    // durable degraded + audit fail → overall must be fail, not degraded.
    $report = SwarmHealthReport::for(healthDurableStub(false), healthAuditStub(healthAuditSummary(deadLetter: 1)));

    expect($report->status)->toBe(HealthStatus::Fail)
        ->and($report->ok)->toBeFalse();
});

test('the status enum is the single home for the Filament color token', function () {
    // Colour lives in exactly one place (HealthStatus::getColor) — the report and
    // the checks both read it straight off the enum.
    expect(HealthStatus::Pass->getColor())->toBe('success')
        ->and(HealthStatus::Fail->getColor())->toBe('danger')
        ->and(HealthStatus::Degraded->getColor())->toBe('gray')
        ->and(HealthStatus::Pass->label())->toBe('Pass')
        ->and(HealthStatus::Degraded->label())->toBe('Degraded');
});

test('a health check reports its predicate helpers off the status enum', function () {
    $pass = new HealthCheck('k', 'Label', HealthStatus::Pass, 's');
    $fail = new HealthCheck('k', 'Label', HealthStatus::Fail, 's');
    $degraded = new HealthCheck('k', 'Label', HealthStatus::Degraded, 's');

    expect($pass->passed())->toBeTrue()->and($pass->status->getColor())->toBe('success')
        ->and($fail->failed())->toBeTrue()->and($fail->status->getColor())->toBe('danger')
        ->and($degraded->degraded())->toBeTrue()->and($degraded->status->getColor())->toBe('gray');
});

test('the durable probe never reads a real run — the sentinel id is looked up', function () {
    // Prove the probe id passed to find() is a non-real sentinel, so no payload
    // is ever read to determine reachability.
    $seen = null;
    $durable = new class($seen) implements InspectsDurableRuns
    {
        public function __construct(public mixed &$seen) {}

        public function find(string $runId): ?array
        {
            $this->seen = $runId;

            return null;
        }

        public function inspect(string $runId): DurableRunDetail
        {
            throw new RuntimeException('not used');
        }

        public function inspectByLabels(array $labels, int $limit = 50): array
        {
            return [];
        }
    };

    SwarmHealthReport::for($durable, healthAuditStub(healthAuditSummary()));

    expect($durable->seen)->toContain('health_probe');
});

// ---------------------------------------------------------------------------
// Page + widget static shape — no Livewire render (see the file header on the
// deferred render coverage). The auth gate is covered in AuthorizationGateTest.
// ---------------------------------------------------------------------------

test('the health page is a SwarmPage bound to the health view and slug', function () {
    $defaults = (new ReflectionClass(SwarmHealthPage::class))->getDefaultProperties();

    expect(is_subclass_of(SwarmHealthPage::class, SwarmPage::class))->toBeTrue()
        ->and($defaults['view'])->toBe('swarm-filament::pages.swarm-health')
        ->and($defaults['slug'])->toBe('swarm-health')
        ->and($defaults['title'])->toBe('Swarm health')
        ->and($defaults['navigationLabel'])->toBe('Health');
});

test('the health page navigation group and sort are driven by config', function () {
    expect(SwarmHealthPage::getNavigationGroup())->toBe('Swarm')
        ->and(SwarmHealthPage::getNavigationSort())->toBeNull();

    config()->set('swarm-filament.navigation.group', 'Observability');
    config()->set('swarm-filament.navigation.sort', 5);

    expect(SwarmHealthPage::getNavigationGroup())->toBe('Observability')
        ->and(SwarmHealthPage::getNavigationSort())->toBe(5);

    // A non-int sort falls back to null rather than mis-typing the sort.
    config()->set('swarm-filament.navigation.sort', 'oops');
    expect(SwarmHealthPage::getNavigationSort())->toBeNull();
});

test('the health widget is a SwarmWidget bound to the health widget view', function () {
    $defaults = (new ReflectionClass(SwarmHealthWidget::class))->getDefaultProperties();

    expect(is_subclass_of(SwarmHealthWidget::class, SwarmWidget::class))->toBeTrue()
        ->and($defaults['view'])->toBe('swarm-filament::widgets.swarm-health')
        ->and($defaults['columnSpan'])->toBe('full');
});

test('the page and widget resolve a report through the real bound read seams', function () {
    // In the harness (cache persistence) the durable inspector is DB-backed with no
    // migrated tables and the audit outbox is the no-op — both lanes degrade, so the
    // real verdict is DEGRADED but ok (no hard fail). Asserting the actual verdict —
    // not just instanceof — catches a container binding that starts throwing or
    // mis-reporting rather than degrading.
    $pageReport = (new SwarmHealthPage)->report();
    $widgetReport = (new SwarmHealthWidget)->report();

    expect($pageReport)->toBeInstanceOf(SwarmHealthReport::class)
        ->and($pageReport->checks)->toHaveCount(2)
        ->and($pageReport->status)->toBe(HealthStatus::Degraded)
        ->and($pageReport->ok)->toBeTrue()
        ->and($widgetReport)->toBeInstanceOf(SwarmHealthReport::class)
        ->and($widgetReport->status)->toBe(HealthStatus::Degraded)
        ->and($widgetReport->ok)->toBeTrue();
});
