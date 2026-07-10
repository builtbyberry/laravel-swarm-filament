<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Contracts\InspectsDurableRuns;
use BuiltByBerry\LaravelSwarm\Responses\DurableRunDetail;
use BuiltByBerry\LaravelSwarmFilament\Resources\SwarmRunResource\Pages\ViewSwarmRun;

/*
 * ViewSwarmRun::selectFlow is the flow-source precedence seam: a durable run's
 * authored route_plan (the true DAG) when present, else the topology-derived flow
 * over run history. And durableDetail must NEVER throw — a failing or absent durable
 * inspection degrades to null so the page falls back to run history rather than
 * 500-ing every non-durable run (the common case). Both were extracted as testable
 * statics in the run-centric redesign but had no direct coverage.
 */

function flowHistory(): array
{
    return [
        'status' => 'completed',
        'topology' => 'sequential',
        'steps' => [
            ['step_index' => 0, 'agent_class' => 'App\\Agents\\A'],
            ['step_index' => 1, 'agent_class' => 'App\\Agents\\B'],
        ],
    ];
}

test('selectFlow prefers the durable route plan when one is present', function () {
    $run = [
        'status' => 'completed',
        'completed_node_ids' => ['gather', 'write'],
        'route_plan' => [
            'start_at' => 'gather',
            'nodes' => [
                'gather' => ['type' => 'worker', 'agent' => 'App\\Agents\\Gather', 'next' => 'write'],
                'write' => ['type' => 'finish', 'output_from' => 'gather'],
            ],
        ],
    ];

    $graph = ViewSwarmRun::selectFlow($run, [], [], [], flowHistory(), []);

    // plan-derived nodes carry the `plan:` prefix; the history flow would emit `step-`.
    expect(array_column($graph['nodes'], 'id'))->toContain('plan:gather', 'plan:write');
});

test('selectFlow decodes a string route_plan', function () {
    $run = ['route_plan' => json_encode([
        'nodes' => ['n' => ['type' => 'worker', 'agent' => 'App\\Agents\\N']],
    ])];

    $graph = ViewSwarmRun::selectFlow($run, [], [], [], flowHistory(), []);

    expect(array_column($graph['nodes'], 'id'))->toBe(['plan:n']);
});

test('selectFlow falls back to run history when there is no plan and no durable detail', function () {
    $graph = ViewSwarmRun::selectFlow([], [], [], [], flowHistory(), []);

    expect(array_column($graph['nodes'], 'id'))->toBe(['step-0', 'step-1']);
});

test('selectFlow falls back to run history when the route plan has no nodes', function () {
    $run = ['route_plan' => ['start_at' => 'x', 'nodes' => []]];

    $graph = ViewSwarmRun::selectFlow($run, [], [], [], flowHistory(), []);

    expect(array_column($graph['nodes'], 'id'))->toBe(['step-0', 'step-1']);
});

test('durableDetail never throws — a failing durable inspection degrades to null (the run page 500-proof)', function () {
    // find() returns a row, but inspect() throws (the "durable store down" case). The
    // page must fall back to run history, not 500 — durableDetail swallows it to null.
    app()->instance(InspectsDurableRuns::class, new class implements InspectsDurableRuns
    {
        public function find(string $runId): ?array
        {
            return ['x' => 1];
        }

        public function inspect(string $runId): DurableRunDetail
        {
            throw new RuntimeException('durable store unavailable');
        }

        public function inspectByLabels(array $labels, int $limit = 50): array
        {
            return [];
        }
    });

    $durableDetail = new ReflectionMethod(ViewSwarmRun::class, 'durableDetail');
    $durableDetail->setAccessible(true);

    expect($durableDetail->invoke(null, 'run-1'))->toBeNull();
});
