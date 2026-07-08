<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarmFilament\Support\RunGraph;
use BuiltByBerry\LaravelSwarmFilament\Support\WorkflowGraphPresenter;

/*
 * Workflow graph views — the pure layout engine (WorkflowGraphPresenter) and the
 * per-surface node/edge adapters (RunGraph). All logic is testable directly, below
 * the Livewire render layer (the SVG Blade partial is a thin projection of this).
 */

// --- WorkflowGraphPresenter: the layered layout geometry ------------------

test('an empty graph is flagged empty and has no nodes', function () {
    $g = WorkflowGraphPresenter::present([], []);

    expect($g['empty'])->toBeTrue()
        ->and($g['nodes'])->toBe([])
        ->and($g['edges'])->toBe([]);
});

test('a chain lays out left-to-right, one node per layer', function () {
    $g = WorkflowGraphPresenter::present(
        [['id' => 'a', 'label' => 'A'], ['id' => 'b', 'label' => 'B'], ['id' => 'c', 'label' => 'C']],
        [['from' => 'a', 'to' => 'b'], ['from' => 'b', 'to' => 'c']],
    );

    $x = [];
    foreach ($g['nodes'] as $n) {
        $x[$n['id']] = $n['x'];
    }

    expect($g['empty'])->toBeFalse()
        ->and($x['a'])->toBeLessThan($x['b'])
        ->and($x['b'])->toBeLessThan($x['c'])
        ->and($g['edges'])->toHaveCount(2)
        // each edge carries an SVG path
        ->and($g['edges'][0]['d'])->toStartWith('M ');
});

test('siblings share a layer (same x) and stack vertically (distinct y)', function () {
    $g = WorkflowGraphPresenter::present(
        [['id' => 'root', 'label' => 'R'], ['id' => 'x', 'label' => 'X'], ['id' => 'y', 'label' => 'Y']],
        [['from' => 'root', 'to' => 'x'], ['from' => 'root', 'to' => 'y']],
    );
    $pos = [];
    foreach ($g['nodes'] as $n) {
        $pos[$n['id']] = ['x' => $n['x'], 'y' => $n['y']];
    }

    expect($pos['x']['x'])->toBe($pos['y']['x'])          // same layer/column
        ->and($pos['x']['x'])->toBeGreaterThan($pos['root']['x'])
        ->and($pos['x']['y'])->not->toBe($pos['y']['y']); // stacked
});

test('a dangling edge (endpoint missing) is dropped, not rendered', function () {
    $g = WorkflowGraphPresenter::present(
        [['id' => 'a', 'label' => 'A']],
        [['from' => 'a', 'to' => 'ghost'], ['from' => 'a', 'to' => 'a']],
    );

    expect($g['nodes'])->toHaveCount(1)
        ->and($g['edges'])->toBe([]);
});

test('a cycle lays out without hanging (bounded relaxation)', function () {
    $g = WorkflowGraphPresenter::present(
        [['id' => 'a', 'label' => 'A'], ['id' => 'b', 'label' => 'B']],
        [['from' => 'a', 'to' => 'b'], ['from' => 'b', 'to' => 'a']],
    );

    expect($g['nodes'])->toHaveCount(2)
        ->and($g['edges'])->toHaveCount(2)
        ->and($g['width'])->toBeGreaterThan(0);
});

test('duplicate node ids collapse to one node', function () {
    $g = WorkflowGraphPresenter::present(
        [['id' => 'a', 'label' => 'A'], ['id' => 'a', 'label' => 'A-again']],
        [],
    );

    expect($g['nodes'])->toHaveCount(1);
});

// --- RunGraph::fromRun: run-history steps → a flow -------------------------

test('a run flow chains its steps by agent basename', function () {
    $graph = RunGraph::fromRun([
        'status' => 'completed',
        'steps' => [
            ['step_index' => 0, 'agent_class' => 'App\\Agents\\Classifier'],
            ['step_index' => 1, 'agent_class' => 'App\\Agents\\Responder'],
        ],
    ]);

    expect($graph['nodes'])->toHaveCount(2)
        ->and($graph['nodes'][0]['label'])->toBe('Classifier')
        ->and($graph['nodes'][0]['status'])->toBe('completed')
        ->and($graph['edges'])->toHaveCount(1)
        ->and($graph['edges'][0])->toMatchArray(['from' => 'step-0', 'to' => 'step-1']);
});

test('a run with no steps yields an empty flow', function () {
    expect(RunGraph::fromRun(['steps' => []])['nodes'])->toBe([])
        ->and(RunGraph::fromRun([])['edges'])->toBe([]);
});

// --- RunGraph::fromDurable: DurableRunDetail → the DAG ---------------------

test('the durable DAG wires branches by parent, plus a root and child runs', function () {
    $graph = RunGraph::fromDurable([
        'summary' => ['swarm_class' => 'App\\Swarms\\Triage', 'status' => 'completed'],
        'branches' => [
            ['node_id' => 'root', 'parent_node_id' => null, 'agent_class' => 'App\\Agents\\Coordinator', 'status' => 'completed'],
            ['node_id' => 'billing', 'parent_node_id' => 'root', 'agent_class' => 'App\\Agents\\Billing', 'status' => 'completed'],
            ['node_id' => 'tech', 'parent_node_id' => 'root', 'agent_class' => 'App\\Agents\\Tech', 'status' => 'running'],
        ],
        'children' => [
            ['child_run_id' => 'sub-1', 'child_swarm_class' => 'App\\Swarms\\SubFlow', 'status' => 'completed'],
        ],
        'node_outputs' => [],
    ]);

    $ids = array_column($graph['nodes'], 'id');
    expect($ids)->toContain('run')
        ->and($ids)->toContain('node:root')
        ->and($ids)->toContain('node:billing')
        ->and($ids)->toContain('child:sub-1');

    // parent_node_id edges wire the hierarchy; a null parent hangs off the root run.
    expect($graph['edges'])->toContainEqual(['from' => 'run', 'to' => 'node:root', 'kind' => 'branch'])
        ->and($graph['edges'])->toContainEqual(['from' => 'node:root', 'to' => 'node:billing', 'kind' => 'branch'])
        ->and($graph['edges'])->toContainEqual(['from' => 'node:root', 'to' => 'node:tech', 'kind' => 'branch'])
        ->and($graph['edges'])->toContainEqual(['from' => 'run', 'to' => 'child:sub-1', 'kind' => 'child']);

    // labels are agent basenames; the tech branch keeps its running status.
    $byId = collect($graph['nodes'])->keyBy('id');
    expect($byId['node:billing']['label'])->toBe('Billing')
        ->and($byId['node:tech']['status'])->toBe('running')
        ->and($byId['run']['label'])->toBe('Triage');
});

test('durable node outputs without branches chain in recorded order under the root', function () {
    $graph = RunGraph::fromDurable([
        'summary' => ['swarm_class' => 'App\\Swarms\\Digest', 'status' => 'completed'],
        'branches' => [],
        'children' => [],
        'node_outputs' => [
            ['node_id' => 'step:0'],
            ['node_id' => 'step:1'],
        ],
    ]);

    expect($graph['edges'])->toContainEqual(['from' => 'run', 'to' => 'node:step:0', 'kind' => 'node'])
        ->and($graph['edges'])->toContainEqual(['from' => 'node:step:0', 'to' => 'node:step:1', 'kind' => 'node']);
});
