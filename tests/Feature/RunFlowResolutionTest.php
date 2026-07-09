<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Contracts\InspectsDurableRuns;
use BuiltByBerry\LaravelSwarm\Responses\DurableRunDetail;
use BuiltByBerry\LaravelSwarmFilament\Resources\SwarmRunResource\Pages\ViewSwarmRun;

/*
 * resolveFlow chooses the flow source: a durable (static-)hierarchical run's
 * authored route_plan (the true DAG) when present, else the topology-derived flow
 * over run history. It must fall back to run history — never throw — when durable
 * inspection is unbound, the run is unknown, or inspection errors. A regression
 * that threw instead of falling back would 500 the run page for every non-durable
 * run (the common case).
 */

function fakeDurable(?array $run, bool $throwOnInspect = false): InspectsDurableRuns
{
    return new class($run, $throwOnInspect) implements InspectsDurableRuns
    {
        public function __construct(private ?array $run, private bool $throwOnInspect) {}

        public function find(string $runId): ?array
        {
            return $this->run;
        }

        public function inspect(string $runId): DurableRunDetail
        {
            if ($this->throwOnInspect) {
                throw new RuntimeException('durable store unavailable');
            }

            return new DurableRunDetail(runId: $runId, run: $this->run);
        }

        public function inspectByLabels(array $labels, int $limit = 50): array
        {
            return [];
        }
    };
}

function historyData(): array
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

test('prefers the durable route plan when one is present', function () {
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

    $graph = ViewSwarmRun::resolveFlow(fakeDurable($run), 'run-1', historyData(), []);

    // plan-derived nodes carry the plan: prefix; the history flow would emit step-
    expect(array_column($graph['nodes'], 'id'))->toContain('plan:gather', 'plan:write');
});

test('decodes a string route_plan', function () {
    $run = [
        'route_plan' => json_encode([
            'nodes' => ['n' => ['type' => 'worker', 'agent' => 'App\\Agents\\N']],
        ]),
    ];

    $graph = ViewSwarmRun::resolveFlow(fakeDurable($run), 'run-1', historyData(), []);

    expect(array_column($graph['nodes'], 'id'))->toBe(['plan:n']);
});

test('falls back to run history when the run is not durable', function () {
    $graph = ViewSwarmRun::resolveFlow(fakeDurable(null), 'run-1', historyData(), []);

    expect(array_column($graph['nodes'], 'id'))->toBe(['step-0', 'step-1']);
});

test('falls back to run history when the route plan has no nodes', function () {
    $run = ['route_plan' => ['start_at' => 'x', 'nodes' => []]];

    $graph = ViewSwarmRun::resolveFlow(fakeDurable($run), 'run-1', historyData(), []);

    expect(array_column($graph['nodes'], 'id'))->toBe(['step-0', 'step-1']);
});

test('never throws — a failing durable inspection degrades to run history', function () {
    // find() returns a row, but inspect() throws (the "durable store down" case).
    // resolveFlow must catch and render the history flow, not 500 the page.
    $graph = ViewSwarmRun::resolveFlow(fakeDurable(['x' => 1], throwOnInspect: true), 'run-1', historyData(), []);

    expect(array_column($graph['nodes'], 'id'))->toBe(['step-0', 'step-1']);
});
