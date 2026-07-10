<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Audit\NoOpSwarmAuditSink;
use BuiltByBerry\LaravelSwarm\Contracts\ReadableSwarmAuditSink;
use BuiltByBerry\LaravelSwarm\Responses\DurableRunDetail;
use BuiltByBerry\LaravelSwarmFilament\Support\AuditTracePresenter;
use BuiltByBerry\LaravelSwarmFilament\Support\DurableExecutionPresenter;
use BuiltByBerry\LaravelSwarmFilament\Support\RunDisplayPresenter;
use BuiltByBerry\LaravelSwarmFilament\Support\StreamTimelinePresenter;
use BuiltByBerry\LaravelSwarmFilament\Support\WorkflowGraphPresenter;
use Illuminate\Support\Facades\View;

/*
 * Blade partial render coverage (audit F12).
 *
 * The five ViewEntry partials on the run page (graph, timeline, audit-timeline,
 * artifacts, durable-execution) each open with an unguarded `@php` block: a
 * status/category→hex `match`, a Carbon-parsing timestamp formatter, and inline
 * degrade rendering. A pure presenter unit test never executes that Blade — a
 * malformed `occurred_at` or a wrong status→colour would slip through. These tests
 * render each partial with `view(...)->render()`, feeding data shaped by the REAL
 * presenter (real-contract data-path, not hand-rolled fakes that could drift), and
 * assert on the emitted HTML for three things per partial where applicable:
 *   (1) the empty-state note when there is no data;
 *   (2) a known status/category dot or badge carries its expected hex colour;
 *   (3) a malformed timestamp (or other edge value) renders raw rather than throwing.
 *
 * These are partial renders below the Livewire layer, so they do NOT hit the
 * Filament v5 + Testbench getErrorBag()-null page-render harness bug.
 *
 * NOT covered here: resources/views/pages/swarm-health.blade.php and
 * resources/views/widgets/swarm-health.blade.php. Unlike the five ViewEntry
 * partials, those two are Filament page/widget component views — they wrap their
 * body in <x-filament-panels::page> / <x-filament-widgets::widget>, which
 * dereference `$this` (the Livewire component: getCachedSubNavigation(),
 * getColumnSpan()). A standalone view(...)->render() therefore throws "Using $this
 * when not in object context", and a Livewire render trips the getErrorBag()-null
 * harness bug — so neither render path is available in this harness. Their only
 * `@php` logic is the HealthStatus→hex/colour mapping, already covered off the
 * enum by HealthDashboardTest (HealthStatus::getColor) + the report-shape and
 * gate tests. See the PR body for the finding write-up.
 */

// A readable audit sink whose forRun() yields a scripted record set — the real
// ReadableSwarmAuditSink contract AuditTracePresenter consumes.
/** @param list<mixed> $records */
function f12ReadableAuditSink(array $records): ReadableSwarmAuditSink
{
    return new class($records) implements ReadableSwarmAuditSink
    {
        /** @param list<mixed> $records */
        public function __construct(private array $records) {}

        public function emit(string $category, array $payload): void {}

        public function forRun(string $runId): iterable
        {
            return $this->records;
        }
    };
}

// ---------------------------------------------------------------------------
// graph.blade.php  (swarm-filament::graph, var $graph — WorkflowGraphPresenter)
// ---------------------------------------------------------------------------

test('graph partial renders the empty-state note when the presenter reports no nodes', function () {
    $graph = WorkflowGraphPresenter::present([], []);

    $html = View::make('swarm-filament::graph', ['graph' => $graph])->render();

    expect($html)->toContain('No workflow to display for this run.');
});

test('graph partial paints each node fill from the status→hex match', function () {
    $graph = WorkflowGraphPresenter::present(
        nodes: [
            ['id' => 'a', 'label' => 'Planner', 'status' => 'completed'],
            ['id' => 'b', 'label' => 'Worker', 'status' => 'failed'],
            ['id' => 'c', 'label' => 'Waiter', 'status' => 'running'],
        ],
        edges: [['from' => 'a', 'to' => 'b'], ['from' => 'b', 'to' => 'c']],
    );

    $html = View::make('swarm-filament::graph', ['graph' => $graph])->render();

    // The status→hex match executes: completed=green, failed=red, running=amber.
    expect($html)->toContain('fill="#16a34a"')   // completed
        ->and($html)->toContain('fill="#dc2626"') // failed
        ->and($html)->toContain('fill="#d97706"') // running
        ->and($html)->toContain('Planner');
});

test('graph partial renders a node with an unknown/absent status via the default hex without throwing', function () {
    // A node carrying only an id (no status) exercises the match default arm.
    $graph = WorkflowGraphPresenter::present(nodes: [['id' => 'lonely']], edges: []);

    $html = View::make('swarm-filament::graph', ['graph' => $graph])->render();

    expect($html)->toContain('fill="#64748b"') // default (null status)
        ->and($html)->toContain('lonely');
});

// ---------------------------------------------------------------------------
// timeline.blade.php  (swarm-filament::timeline, var $timeline — StreamTimelinePresenter)
// ---------------------------------------------------------------------------

test('timeline partial renders the empty-state note when the fold yields no nodes', function () {
    $timeline = StreamTimelinePresenter::build([]);

    $html = View::make('swarm-filament::timeline', ['timeline' => $timeline])->render();

    expect($html)->toContain('No streamed events for this run.');
});

test('timeline partial colours marker chips from the marker_color→hex match', function () {
    // A void-edge targeting a missing predecessor → danger; a present event voided
    // by a supersede edge → warning. Both marker_color values come straight from
    // the real fold, so the chip hex the Blade paints is the contract's.
    $timeline = StreamTimelinePresenter::build([
        ['id' => 'e1', 'type' => 'swarm_step_start', 'node_id' => 'n1', 'step_index' => 0],
        ['type' => 'swarm_causal_void_edge', 'target_event_id' => 'e1', 'void_type' => 'supersedes', 'reason' => 'retry'],
        ['type' => 'swarm_causal_void_edge', 'target_event_id' => 'ghost', 'void_type' => 'abandons', 'reason' => 'lost'],
    ]);

    $html = View::make('swarm-filament::timeline', ['timeline' => $timeline])->render();

    expect($html)->toContain('--chip: #dc2626')   // danger: missing predecessor
        ->and($html)->toContain('--chip: #d97706'); // warning: voided event
});

test('timeline partial falls back to the default chip hex for an unmarked event without throwing', function () {
    $timeline = StreamTimelinePresenter::build([
        ['id' => 'e1', 'type' => 'swarm_step_start', 'node_id' => 'n1', 'step_index' => 0],
    ]);

    $html = View::make('swarm-filament::timeline', ['timeline' => $timeline])->render();

    // An unvoided event has marker_color null → chipColor default.
    expect($html)->toContain('--chip: #64748b')
        ->and($html)->toContain('Step start');
});

// ---------------------------------------------------------------------------
// audit-timeline.blade.php  (swarm-filament::audit-timeline, var $audit — AuditTracePresenter)
// ---------------------------------------------------------------------------

test('audit-timeline partial renders the sink-guidance empty-state when nothing is stored', function () {
    // The default no-op sink stores nothing → the presenter emits a self-explaining
    // note, which the empty-state renders instead of a bare empty trail.
    $audit = AuditTracePresenter::present(new NoOpSwarmAuditSink, 'run-1');

    $html = View::make('swarm-filament::audit-timeline', ['audit' => $audit])->render();

    expect($html)->toContain('No readable audit sink is bound');
});

test('audit-timeline partial paints category dots from the evidence-family match', function () {
    $audit = AuditTracePresenter::present(f12ReadableAuditSink([
        ['category' => 'run.completed', 'occurred_at' => '2026-07-09T10:00:00Z', 'run_id' => 'run-1'],
        ['category' => 'step.started', 'occurred_at' => '2026-07-09T10:01:00Z', 'run_id' => 'run-1'],
        ['category' => 'delivery.failed', 'occurred_at' => '2026-07-09T10:02:00Z', 'run_id' => 'run-1'],
    ]), 'run-1');

    $html = View::make('swarm-filament::audit-timeline', ['audit' => $audit])->render();

    expect($html)->toContain('--dot: #7c5cff')   // run.* → violet
        ->and($html)->toContain('--dot: #2563eb') // step.* → blue
        ->and($html)->toContain('--dot: #dc2626') // *fail* → red
        ->and($html)->toContain('run.completed');
});

test('audit-timeline partial renders a MALFORMED occurred_at raw rather than throwing at render', function () {
    // The @php $fmt closure runs Carbon::parse inside a try/catch; a value Carbon
    // cannot parse must fall to the catch and render the raw string — the unguarded
    // closure a pure presenter test never exercises.
    $audit = AuditTracePresenter::present(f12ReadableAuditSink([
        ['category' => 'run.note', 'occurred_at' => 'not-a-real-timestamp', 'run_id' => 'run-1'],
    ]), 'run-1');

    $html = View::make('swarm-filament::audit-timeline', ['audit' => $audit])->render();

    expect($html)->toContain('not-a-real-timestamp') // raw string, not a formatted time
        ->and($html)->toContain('run.note');
});

// ---------------------------------------------------------------------------
// artifacts.blade.php  (swarm-filament::artifacts, var $artifacts — RunDisplayPresenter)
// ---------------------------------------------------------------------------

test('artifacts partial renders the empty-state note when the run produced none', function () {
    $artifacts = RunDisplayPresenter::present(['artifacts' => []])['artifacts'];

    $html = View::make('swarm-filament::artifacts', ['artifacts' => $artifacts])->render();

    expect($html)->toContain('This run produced no artifacts.');
});

test('artifacts partial renders name, producing agent basename, and content', function () {
    $artifacts = RunDisplayPresenter::present(['artifacts' => [
        ['name' => 'summary.md', 'content' => 'the final answer', 'step_agent_class' => 'App\\Agents\\Reporter'],
    ]])['artifacts'];

    $html = View::make('swarm-filament::artifacts', ['artifacts' => $artifacts])->render();

    expect($html)->toContain('summary.md')
        ->and($html)->toContain('the final answer')
        ->and($html)->toContain('Reporter')          // class_basename of the agent
        ->and($html)->not->toContain('App\\Agents'); // only the basename is shown
});

test('artifacts partial renders a sealed content leaf as the masked placeholder, never raw ciphertext', function () {
    // The presenter routes content through the sealed chokepoint; a still-sw0: value
    // degrades to "unavailable" and must never render raw in the Blade.
    $artifacts = RunDisplayPresenter::present(['artifacts' => [
        ['name' => 'leak.txt', 'content' => 'sw0:ciphertext', 'step_agent_class' => null],
    ]])['artifacts'];

    $html = View::make('swarm-filament::artifacts', ['artifacts' => $artifacts])->render();

    expect($html)->toContain('unavailable')
        ->and($html)->not->toContain('sw0:');
});

// ---------------------------------------------------------------------------
// durable-execution.blade.php  (swarm-filament::durable-execution, var $durable — DurableExecutionPresenter)
// ---------------------------------------------------------------------------

/**
 * @param  array<string, mixed>  $run
 * @param  array<int, array<string, mixed>>  $waits
 * @param  array<int, array<string, mixed>>  $signals
 * @param  array<int, array<string, mixed>>  $progress
 */
function f12DurableDetail(array $run = [], array $waits = [], array $signals = [], array $progress = []): DurableRunDetail
{
    return new DurableRunDetail(
        runId: $run['run_id'] ?? 'run-1',
        run: $run,
        waits: $waits,
        signals: $signals,
        progress: $progress,
    );
}

test('durable partial renders the three empty-state notes and the run status', function () {
    $durable = DurableExecutionPresenter::present(f12DurableDetail(['status' => 'running']));

    $html = View::make('swarm-filament::durable-execution', ['durable' => $durable])->render();

    expect($html)->toContain('This run is not blocked on any waits.')
        ->and($html)->toContain('This run received no signals.')
        ->and($html)->toContain('This run recorded no progress markers.')
        ->and($html)->toContain('running'); // the framework-set status scalar
});

test('durable partial renders wait/signal status badges for known statuses', function () {
    $durable = DurableExecutionPresenter::present(f12DurableDetail(
        run: ['status' => 'waiting'],
        waits: [['name' => 'approval', 'status' => 'waiting']],
        signals: [['name' => 'approve', 'status' => 'consumed', 'consumed_at' => '2026-07-09 21:05:00']],
    ));

    $html = View::make('swarm-filament::durable-execution', ['durable' => $durable])->render();

    expect($html)->toContain('approval')
        ->and($html)->toContain('waiting')  // wait status badge
        ->and($html)->toContain('approve')
        ->and($html)->toContain('consumed'); // signal status badge
});

test('durable partial renders a MALFORMED timeout timestamp raw rather than throwing', function () {
    // Durable timestamps are rendered raw (no Carbon parse) through the $shown guard;
    // an unparseable value must still render literally without throwing.
    $durable = DurableExecutionPresenter::present(f12DurableDetail(
        waits: [['name' => 'approval', 'status' => 'waiting', 'timeout_at' => 'whenever-o-clock']],
    ));

    $html = View::make('swarm-filament::durable-execution', ['durable' => $durable])->render();

    expect($html)->toContain('times out whenever-o-clock');
});
