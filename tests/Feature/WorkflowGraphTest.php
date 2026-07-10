<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarmFilament\Resources\SwarmRunResource\Pages\ViewSwarmRun;
use BuiltByBerry\LaravelSwarmFilament\Support\RunDisplayPresenter;
use BuiltByBerry\LaravelSwarmFilament\Support\RunGraph;
use BuiltByBerry\LaravelSwarmFilament\Support\WorkflowGraphPresenter;
use Illuminate\Support\Js;

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

test('a parallel run fans a synthetic origin out to every branch', function () {
    $graph = RunGraph::fromRun([
        'status' => 'completed',
        'topology' => 'parallel',
        'swarm_class' => 'App\\Swarms\\MarketResearch',
        'steps' => [
            ['step_index' => 0, 'agent_class' => 'App\\Agents\\MarketScout'],
            ['step_index' => 1, 'agent_class' => 'App\\Agents\\CompetitorScout'],
            ['step_index' => 2, 'agent_class' => 'App\\Agents\\CustomerScout'],
        ],
    ]);

    $ids = array_column($graph['nodes'], 'id');
    // the synthetic origin is prepended and labelled by the swarm
    expect($ids[0])->toBe('run-origin')
        ->and($graph['nodes'][0]['label'])->toBe('MarketResearch')
        ->and($graph['nodes'])->toHaveCount(4)
        // origin fans out to each branch; branches do not chain to one another
        ->and($graph['edges'])->toHaveCount(3)
        ->and($graph['edges'])->toContainEqual(['from' => 'run-origin', 'to' => 'step-0', 'kind' => 'parallel'])
        ->and($graph['edges'])->toContainEqual(['from' => 'run-origin', 'to' => 'step-2', 'kind' => 'parallel']);
});

test('a hierarchical run routes the coordinator to every worker', function () {
    $graph = RunGraph::fromRun([
        'status' => 'completed',
        'topology' => 'hierarchical',
        'steps' => [
            ['step_index' => 0, 'agent_class' => 'App\\Agents\\Classifier', 'role' => 'coordinator'],
            ['step_index' => 1, 'agent_class' => 'App\\Agents\\Billing', 'decision' => 'billing'],
            ['step_index' => 2, 'agent_class' => 'App\\Agents\\Tech', 'decision' => 'technical'],
        ],
    ]);

    // no synthetic origin — the coordinator agent IS the root
    expect(array_column($graph['nodes'], 'id'))->toBe(['step-0', 'step-1', 'step-2'])
        ->and($graph['edges'])->toHaveCount(2)
        ->and($graph['edges'])->toContainEqual(['from' => 'step-0', 'to' => 'step-1', 'kind' => 'route'])
        ->and($graph['edges'])->toContainEqual(['from' => 'step-0', 'to' => 'step-2', 'kind' => 'route']);
});

test('a static_hierarchical run falls back to an honest execution-order chain', function () {
    // The true fan-out/join DAG is only recoverable from durable branch records
    // (fromDurable); a non-durable run's flat step list is shown in order.
    $graph = RunGraph::fromRun([
        'status' => 'completed',
        'topology' => 'static_hierarchical',
        'steps' => [
            ['step_index' => 0, 'agent_class' => 'App\\Agents\\ChangeScanner'],
            ['step_index' => 1, 'agent_class' => 'App\\Agents\\RiskScanner'],
            ['step_index' => 2, 'agent_class' => 'App\\Agents\\NotesWriter'],
        ],
    ]);

    expect($graph['nodes'])->toHaveCount(3)
        ->and($graph['edges'])->toHaveCount(2)
        ->and($graph['edges'][0])->toMatchArray(['from' => 'step-0', 'to' => 'step-1', 'kind' => 'sequence']);
});

// --- RunGraph::fromRoutePlan: the authored DAG (durable hierarchical) ------

test('a route plan renders the true fan-out/join diamond with status overlay', function () {
    $graph = RunGraph::fromRoutePlan([
        'start_at' => 'gather',
        'nodes' => [
            'gather' => ['type' => 'parallel', 'branches' => ['changes', 'risks'], 'next' => 'write'],
            'changes' => ['type' => 'worker', 'agent' => 'App\\Agents\\ChangeScanner'],
            'risks' => ['type' => 'worker', 'agent' => 'App\\Agents\\RiskScanner'],
            'write' => ['type' => 'worker', 'agent' => 'App\\Agents\\NotesWriter', 'with_outputs' => ['changes' => 'changes', 'risks' => 'risks'], 'next' => 'finish'],
            'finish' => ['type' => 'finish', 'output_from' => 'write'],
        ],
    ], ['gather', 'changes', 'risks', 'write'], 'completed');

    $byId = collect($graph['nodes'])->keyBy('id');
    // structural + worker labels
    expect($byId['plan:gather']['label'])->toBe('Gather')
        ->and($byId['plan:gather']['sublabel'])->toBe('parallel')
        ->and($byId['plan:changes']['label'])->toBe('ChangeScanner')
        ->and($byId['plan:write']['label'])->toBe('NotesWriter')
        ->and($byId['plan:finish']['label'])->toBe('Finish')
        // status overlaid from completed_node_ids; finish (absent) inherits run status
        ->and($byId['plan:changes']['status'])->toBe('completed')
        ->and($byId['plan:finish']['status'])->toBe('completed');

    // the diamond: gather fans out to both branches, both rejoin at write, then finish
    expect($graph['edges'])->toContainEqual(['from' => 'plan:gather', 'to' => 'plan:changes', 'kind' => 'branch'])
        ->and($graph['edges'])->toContainEqual(['from' => 'plan:gather', 'to' => 'plan:risks', 'kind' => 'branch'])
        ->and($graph['edges'])->toContainEqual(['from' => 'plan:changes', 'to' => 'plan:write', 'kind' => 'join'])
        ->and($graph['edges'])->toContainEqual(['from' => 'plan:risks', 'to' => 'plan:write', 'kind' => 'join'])
        ->and($graph['edges'])->toContainEqual(['from' => 'plan:write', 'to' => 'plan:finish', 'kind' => 'sequence']);
});

test('a route plan overlays incomplete nodes with the run status', function () {
    $graph = RunGraph::fromRoutePlan([
        'nodes' => [
            'a' => ['type' => 'worker', 'agent' => 'App\\Agents\\A', 'next' => 'b'],
            'b' => ['type' => 'worker', 'agent' => 'App\\Agents\\B'],
        ],
    ], ['a'], 'running');

    $byId = collect($graph['nodes'])->keyBy('id');
    expect($byId['plan:a']['status'])->toBe('completed')   // in completed_node_ids
        ->and($byId['plan:b']['status'])->toBe('running');  // inherits the live run status
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

test('the durable DAG grafts each branch its sealed-rendered I/O, tokens, duration, attempts + memory facets', function () {
    // The per-node detail F20 said fromDurable dropped: input/output (display-
    // decrypted), token usage, duration_ms, attempts, and memory by step_index.
    $graph = RunGraph::fromDurable([
        'summary' => ['swarm_class' => 'App\\Swarms\\Triage', 'status' => 'completed'],
        'branches' => [
            ['node_id' => 'billing', 'parent_node_id' => 'root', 'agent_class' => 'App\\Agents\\Billing', 'status' => 'completed',
                'step_index' => 1, 'input' => 'the refund request', 'input_available' => true,
                'output' => '[Billing] Approved the refund.', 'output_available' => true,
                'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 40], 'duration_ms' => 850, 'attempts' => 2],
        ],
        'children' => [],
        'node_outputs' => [],
    ], [1 => ['wrote' => ['decision'], 'entries' => [['key' => 'decision', 'value' => 'refund', 'scope' => 'run']], 'tools' => ['lookup']]]);

    $byId = collect($graph['nodes'])->keyBy('id');
    expect($byId['node:billing']['detail_input'])->toBe('the refund request')
        ->and($byId['node:billing']['detail_output'])->toBe('[Billing] Approved the refund.')
        // summary strips the leading "[Agent] " label like fromRun does
        ->and($byId['node:billing']['summary'])->toBe('Approved the refund.')
        ->and($byId['node:billing']['tokens'])->toBe(140)
        ->and($byId['node:billing']['detail_duration'])->toBe('850ms')
        ->and($byId['node:billing']['detail_attempts'])->toBe(2)
        // memory facets join by step_index
        ->and($byId['node:billing']['detail_wrote'])->toBe(['decision'])
        ->and($byId['node:billing']['detail_tools'])->toBe(['lookup'])
        ->and($byId['node:billing']['detail_memory'][0]['value'])->toBe('refund');
});

test('the durable DAG routes an unavailable / nested-sealed branch payload through the chokepoint (never raw)', function () {
    $graph = RunGraph::fromDurable([
        'summary' => ['swarm_class' => 'App\\Swarms\\Triage', 'status' => 'completed'],
        'branches' => [
            // input failed to decrypt (available=false); output carries a nested sw0: leaf.
            ['node_id' => 'tech', 'parent_node_id' => 'root', 'agent_class' => 'App\\Agents\\Tech', 'status' => 'completed',
                'step_index' => 2, 'input' => null, 'input_available' => false,
                'output' => ['answer' => 'sw0:sealed', 'lang' => 'en'], 'output_available' => true],
        ],
        'children' => [],
        'node_outputs' => [],
    ]);

    $byId = collect($graph['nodes'])->keyBy('id');
    // undecryptable input degrades to the marker, never null/ciphertext
    expect($byId['node:tech']['detail_input'])->toBe('unavailable')
        // the nested sealed leaf is masked; the plaintext sibling survives (partial mask)
        ->and($byId['node:tech']['detail_output'])->toBe('{"answer":"unavailable","lang":"en"}')
        ->and($byId['node:tech']['detail_output'])->not->toContain('sw0:');
});

test('a durable child run surfaces as a node with its sealed-rendered context + output', function () {
    $graph = RunGraph::fromDurable([
        'summary' => ['swarm_class' => 'App\\Swarms\\Parent', 'status' => 'completed'],
        'branches' => [],
        'children' => [
            ['child_run_id' => 'sub-1', 'child_swarm_class' => 'App\\Swarms\\SubFlow', 'status' => 'completed',
                'context_payload' => 'the sub task', 'context_available' => true,
                'output' => 'the sub result', 'output_available' => true],
        ],
        'node_outputs' => [],
    ]);

    $byId = collect($graph['nodes'])->keyBy('id');
    expect($byId['child:sub-1']['detail_input'])->toBe('the sub task')
        ->and($byId['child:sub-1']['detail_output'])->toBe('the sub result')
        ->and($byId['child:sub-1']['summary'])->toBe('the sub result');
});

test('a bare durable node output is sealed-rendered onto its chained node', function () {
    $graph = RunGraph::fromDurable([
        'summary' => ['swarm_class' => 'App\\Swarms\\Digest', 'status' => 'completed'],
        'branches' => [],
        'children' => [],
        'node_outputs' => [
            ['node_id' => 'step:0', 'output' => 'sw0:sealed-leaf', 'output_available' => true],
        ],
    ]);

    // A bare still-sealed scalar degrades wholesale to the marker (never raw sw0:).
    $byId = collect($graph['nodes'])->keyBy('id');
    expect($byId['node:step:0']['detail_output'])->toBe('unavailable')
        ->and($byId['node:step:0']['detail_output'])->not->toContain('sw0:');
});

test('every builder emits the same detail SHAPE so the click-through is uniform', function () {
    $keys = ['summary', 'tokens', 'detail_input', 'detail_output', 'detail_duration', 'detail_attempts', 'detail_wrote', 'detail_memory', 'detail_tools'];

    $run = RunGraph::fromRun(['status' => 'completed', 'steps' => [['step_index' => 0, 'agent_class' => 'X', 'output' => 'y']]]);
    $plan = RunGraph::fromRoutePlan(['nodes' => ['a' => ['type' => 'worker', 'agent' => 'App\\A']]]);
    $durable = RunGraph::fromDurable([
        'summary' => ['swarm_class' => 'App\\S', 'status' => 'completed'],
        'branches' => [['node_id' => 'a', 'parent_node_id' => null, 'agent_class' => 'App\\A', 'status' => 'completed', 'step_index' => 0, 'input' => 'i', 'input_available' => true, 'output' => 'o', 'output_available' => true]],
        'children' => [], 'node_outputs' => [],
    ]);

    foreach ([$run, $plan, $durable] as $graph) {
        foreach ($graph['nodes'] as $node) {
            expect($node)->toHaveKeys($keys);
        }
    }
});

test('fromRoutePlan grafts a durable branch onto its authored worker node (node_id match)', function () {
    $graph = RunGraph::fromRoutePlan(
        ['start_at' => 'gather', 'nodes' => [
            'gather' => ['type' => 'parallel', 'branches' => ['changes'], 'next' => 'write'],
            'changes' => ['type' => 'worker', 'agent' => 'App\\Agents\\ChangeScanner'],
            'write' => ['type' => 'worker', 'agent' => 'App\\Agents\\NotesWriter', 'next' => 'finish'],
            'finish' => ['type' => 'finish'],
        ]],
        ['gather', 'changes'],
        'running',
        // durable branch rows carry the real execution I/O for the plan nodes
        [['node_id' => 'changes', 'agent_class' => 'App\\Agents\\ChangeScanner', 'status' => 'completed',
            'step_index' => 0, 'input' => 'the diff', 'input_available' => true,
            'output' => 'listed 3 changes', 'output_available' => true,
            'usage' => ['prompt_tokens' => 60, 'completion_tokens' => 20], 'duration_ms' => 1500]],
        [0 => ['wrote' => ['changes'], 'entries' => [], 'tools' => []]],
    );

    $byId = collect($graph['nodes'])->keyBy('id');
    // the worker node is grafted with its branch's sealed-rendered detail
    expect($byId['plan:changes']['detail_input'])->toBe('the diff')
        ->and($byId['plan:changes']['detail_output'])->toBe('listed 3 changes')
        ->and($byId['plan:changes']['tokens'])->toBe(80)
        ->and($byId['plan:changes']['detail_duration'])->toBe('1.5s')
        ->and($byId['plan:changes']['detail_wrote'])->toBe(['changes'])
        // structural nodes (no branch) keep the empty detail shape
        ->and($byId['plan:gather']['detail_input'])->toBeNull()
        ->and($byId['plan:finish']['detail_output'])->toBeNull();
});

// --- ViewSwarmRun::selectFlow: the flow-source precedence -----------------
// The precedence decision extracted from resolveFlow so it is testable below the
// container/Livewire render layer (Filament v5 + Testbench harness is unreliable).

test('selectFlow prefers the authored route plan when the durable run carries one', function () {
    $flow = ViewSwarmRun::selectFlow(
        ['route_plan' => ['nodes' => ['a' => ['type' => 'worker', 'agent' => 'App\\A', 'next' => 'b'], 'b' => ['type' => 'worker', 'agent' => 'App\\B']]], 'completed_node_ids' => ['a'], 'status' => 'running'],
        [], [], [],
        ['status' => 'x', 'steps' => [['step_index' => 0, 'agent_class' => 'App\\ShouldNotAppear']]],
        [],
    );

    // route-plan ids are plan:*, proving fromRoutePlan won over fromRun/fromDurable
    expect(array_column($flow['nodes'], 'id'))->toContain('plan:a')
        ->and(array_column($flow['nodes'], 'id'))->not->toContain('step-0');
});

test('selectFlow uses the durable DAG when there is no plan but branch/child data exists', function () {
    $flow = ViewSwarmRun::selectFlow(
        ['swarm_class' => 'App\\Swarms\\Triage', 'status' => 'completed'],
        [['node_id' => 'billing', 'parent_node_id' => null, 'agent_class' => 'App\\Agents\\Billing', 'status' => 'completed', 'step_index' => 0, 'input' => 'i', 'input_available' => true, 'output' => 'o', 'output_available' => true]],
        [], [],
        ['status' => 'x', 'steps' => [['step_index' => 0, 'agent_class' => 'App\\ShouldNotAppear']]],
        [],
    );

    // durable ids are run/node:*, proving fromDurable won over fromRun
    expect(array_column($flow['nodes'], 'id'))->toContain('run')
        ->and(array_column($flow['nodes'], 'id'))->toContain('node:billing')
        ->and(array_column($flow['nodes'], 'id'))->not->toContain('step-0');
});

test('selectFlow falls back to run history when the durable run has neither plan nor branch/child data', function () {
    $flow = ViewSwarmRun::selectFlow(
        ['status' => 'completed'],
        [], [], [],
        ['status' => 'completed', 'steps' => [['step_index' => 0, 'agent_class' => 'App\\Agents\\Solo']]],
        [],
    );

    expect(array_column($flow['nodes'], 'id'))->toBe(['step-0']);
});

// --- Enriched flow annotations (role / decision / tokens / summary) -------

test('the presenter extracts per-step role, decision, and tokens from metadata', function () {
    $presented = RunDisplayPresenter::present([
        'status' => 'completed', 'started_at' => '2026-07-08 21:25:00', 'finished_at' => '2026-07-08 21:25:03',
        'usage' => ['prompt_tokens' => 120, 'completion_tokens' => 80],
        'steps' => [
            ['step_index' => 0, 'agent_class' => 'App\\Agents\\Classifier', 'output' => 'plan', 'output_available' => true,
                'metadata' => ['node_role' => 'coordinator', 'usage' => ['prompt_tokens' => 40, 'completion_tokens' => 10]]],
            ['step_index' => 1, 'agent_class' => 'App\\Agents\\Billing', 'output' => 'done', 'output_available' => true,
                'metadata' => ['category' => 'billing']],
        ],
    ]);

    expect($presented['steps'][0]['role'])->toBe('coordinator')
        ->and($presented['steps'][0]['tokens'])->toBe(50)
        ->and($presented['steps'][1]['decision'])->toBe('billing')
        ->and($presented['steps'][1]['tokens'])->toBeNull()      // no usage → not reported
        // run headline metrics
        ->and($presented['metrics']['steps'])->toBe(2)
        ->and($presented['metrics']['tokens'])->toBe(200)
        ->and($presented['metrics']['duration'])->toBe('3s');
});

test('scripted runs report no tokens or duration (metrics stay null, not zero noise)', function () {
    $presented = RunDisplayPresenter::present([
        'status' => 'completed', 'started_at' => '2026-07-08 21:25:00', 'finished_at' => '2026-07-08 21:25:00',
        'usage' => ['prompt_tokens' => 0, 'completion_tokens' => 0],
        'steps' => [['step_index' => 0, 'agent_class' => 'X', 'output' => 'y', 'output_available' => true, 'metadata' => []]],
    ]);

    expect($presented['metrics']['tokens'])->toBeNull()
        ->and($presented['metrics']['duration'])->toBeNull();
});

test('the run graph annotates nodes with the decision and a clipped output summary', function () {
    $graph = RunGraph::fromRun([
        'status' => 'completed',
        'steps' => [
            ['step_index' => 0, 'agent_class' => 'App\\Agents\\Classifier', 'role' => 'coordinator', 'output' => 'route plan'],
            ['step_index' => 1, 'agent_class' => 'App\\Agents\\Billing', 'decision' => 'billing',
                'input' => 'the request', 'output' => '[Billing] Proposed a refund.', 'tokens' => 220],
        ],
    ]);

    $byId = collect($graph['nodes'])->keyBy('id');
    expect($byId['step-0']['sublabel'])->toBe('coordinator')
        ->and($byId['step-1']['sublabel'])->toBe('via billing')
        // leading "[Agent] " stripped for the card gist; full text kept for detail
        ->and($byId['step-1']['summary'])->toBe('Proposed a refund.')
        ->and($byId['step-1']['detail_output'])->toBe('[Billing] Proposed a refund.')
        ->and($byId['step-1']['detail_input'])->toBe('the request')
        ->and($byId['step-1']['tokens'])->toBe(220);
});

test('summarize (F14) pins the label-strip + whitespace-collapse rewrite byte-identically', function () {
    // The Str::of()->replaceMatches()->trim() rewrite of the former preg_replace
    // chain must stay byte-identical: BOTH regexes fire here — a leading "[label] "
    // is stripped, then a run of spaces, tabs, and newlines collapses to one space.
    $graph = RunGraph::fromRun([
        'status' => 'completed',
        'steps' => [
            ['step_index' => 0, 'agent_class' => 'App\\Agents\\Writer',
                'output' => "[Writer]  Drafted   the\treply\nacross\nlines."],
        ],
    ]);

    expect(collect($graph['nodes'])->keyBy('id')['step-0']['summary'])
        ->toBe('Drafted the reply across lines.');
});

test('WorkflowGraphPresenter preserves extra node fields through layout', function () {
    $g = WorkflowGraphPresenter::present(
        [['id' => 'n', 'label' => 'N', 'summary' => 'did a thing', 'tokens' => 42, 'detail_output' => 'full']],
        [],
    );

    expect($g['nodes'][0]['summary'])->toBe('did a thing')
        ->and($g['nodes'][0]['tokens'])->toBe(42)
        ->and($g['nodes'][0]['detail_output'])->toBe('full')
        ->and($g['nodes'][0])->toHaveKeys(['x', 'y', 'w', 'h']);
});

// --- Node-id escaping in the Alpine SVG partial (finding #F19) -------------
// A node id flows from run/plan data (agent- and tool-influenced) into Alpine
// x-on / x-bind EXPRESSION attributes. Those attribute values are evaluated as
// JavaScript, so HTML-escaping alone is not enough — a raw quote survives HTML
// decoding and breaks out of the JS string. The partial must JS-encode ids
// (Blade @js) so a crafted id cannot break the attribute or inject an expression.

// Tested at the source + encoder level, not via a Blade render: the Livewire /
// view-render harness is unreliable here (Filament v5 + Testbench), so we guard
// the two things that make the fix correct — (1) every id site in the partial
// goes through @js, and (2) @js actually neutralises a breakout payload.

test('the graph partial JS-encodes every node/edge id in its Alpine expression attributes', function () {
    $partial = file_get_contents(dirname(__DIR__, 2).'/resources/views/graph.blade.php');

    // Ids must flow through @js (Blade's JS encoder), never a hand-rolled
    // single-quoted interpolation that a crafted id could break out of.
    expect($partial)
        ->toContain('@js($node[\'id\'])')
        ->toContain('@js($edge[\'from\'])')
        ->toContain('@js($edge[\'to\'])')
        // No node/edge field may be interpolated inside a single-quoted JS literal.
        ->not->toContain('\'{{ $node')
        ->not->toContain('\'{{ $edge');
});

test('Blade @js hex-escapes a crafted id so it cannot break out of the Alpine JS string', function () {
    // The encoder @js expands to — proving the mechanism the partial now relies on.
    // An id engineered to close the string and run an expression if left unescaped:
    //   sel === 'evil'-alert(1)-'x'  →  string, then -alert(1)-, then string.
    $encoded = Js::from("evil'-alert(1)-'x")->toHtml();

    // Every apostrophe is hex-escaped ('), so it can never close the literal;
    // the raw breakout form is therefore absent.
    expect($encoded)
        ->toBe('\'evil\\u0027-alert(1)-\\u0027x\'')
        ->not->toContain("'evil'");
});
