<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Contracts\RunHistoryStore;
use BuiltByBerry\LaravelSwarm\Responses\SwarmResponse;
use BuiltByBerry\LaravelSwarm\Support\RunContext;
use BuiltByBerry\LaravelSwarmFilament\Models\SwarmRun;
use BuiltByBerry\LaravelSwarmFilament\Support\RunDisplayPresenter;
use BuiltByBerry\LaravelSwarmFilament\Support\RunGraph;
use BuiltByBerry\LaravelSwarmFilament\Support\WorkflowGraphPresenter;
use BuiltByBerry\LaravelSwarmFilament\Tests\Support\UsageRenderHarness as Render;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\View;

beforeEach(function () {
    $this->freezeTime();
    config()->set('swarm.persistence.driver', 'database');
    Artisan::call('migrate', ['--database' => 'testing']);
});

function usageRenderRecord(mixed $usage): SwarmRun
{
    $id = 'usage-'.bin2hex(random_bytes(4));
    $context = new RunContext($id, 'Task');
    $store = app(RunHistoryStore::class);
    $store->start($id, 'App\\Swarms\\Demo', 'sequential', $context, [], 3600);
    $store->complete($id, new SwarmResponse('Done', usage: is_array($usage) ? $usage : [], context: $context), 3600);
    if (! is_array($usage)) {
        // Corrupt/legacy persisted values are fixture input, never a panel write.
        DB::table('swarm_run_histories')->where('run_id', $id)->update(['usage' => json_encode($usage)]);
    }

    return SwarmRun::query()->findOrFail($id);
}

test('renders conservative usage in the real runs Tokens column', function (mixed $usage, string $label) {
    expect(Render::text(Render::column(usageRenderRecord($usage))))->toBe($label);
})->with(Render::cases());

test('renders conservative usage in the real run detail Section', function (mixed $usage, string $label) {
    $record = usageRenderRecord($usage);
    $before = DB::table('swarm_run_histories')->where('run_id', $record->run_id)->first();
    $html = Render::detail($record);
    preg_match('/<h[1-6][^>]*>(.*?)<\/h[1-6]>/s', $html, $heading);
    expect(Render::text($heading[1]))->toBe('Demo · Completed · sequential · 0 steps · '.$label.' tokens')
        ->and(DB::table('swarm_run_histories')->where('run_id', $record->run_id)->first())->toEqual($before);
})->with(Render::cases());

test('renders conservative usage in the real recent Tokens Stat', function (mixed $usage, string $label) {
    usageRenderRecord($usage);
    expect(Render::text(Render::stat()))->toBe('Tokens '.$label.' over last 1 runs');
})->with(Render::cases());

test('renders complete homogeneous windows and refuses mixed or unknown subtotals in the real Stat', function (array $reports, string $label) {
    foreach ($reports as $report) {
        usageRenderRecord($report);
    }
    expect(Render::text(Render::stat()))->toBe('Tokens '.$label.' over last '.count($reports).' runs');
})->with([
    'no contributors' => [[], '0'],
    'native window' => [[['input_tokens' => 10, 'output_tokens' => 2], ['input_tokens' => 4, 'output_tokens' => 0]], '16'],
    'legacy window' => [[['prompt_tokens' => 10, 'completion_tokens' => 2], ['prompt_tokens' => 4, 'completion_tokens' => 0]], '16'],
    'mixed window' => [[['input_tokens' => 10, 'output_tokens' => 2], ['prompt_tokens' => 4, 'completion_tokens' => 0]], 'Mixed or unavailable'],
    'known then unknown' => [[['input_tokens' => 10, 'output_tokens' => 2], []], 'Unavailable'],
    'unknown then known' => [[[], ['input_tokens' => 10, 'output_tokens' => 2]], 'Unavailable'],
]);

function renderUsageGraph(array $graph): string
{
    return View::make('swarm-filament::graph', ['graph' => WorkflowGraphPresenter::present($graph['nodes'], $graph['edges'])])->render();
}

test('renders conservative usage across history durable and route-plan graph views', function (mixed $usage, string $label) {
    $step = ['step_index' => 0, 'agent_class' => 'Agent', 'metadata' => ['usage' => $usage], 'output' => ['secret' => 'sw0:must-mask', 'safe' => 'visible']];
    $branch = ['node_id' => 'worker', 'agent_class' => 'Agent', 'status' => 'completed', 'usage' => $usage, 'output' => ['secret' => 'sw0:must-mask', 'safe' => 'visible']];
    $plan = ['nodes' => ['worker' => ['type' => 'worker', 'agent' => 'Agent']]];
    foreach ([
        RunGraph::fromRun(RunDisplayPresenter::present(['steps' => [$step]])),
        RunGraph::fromDurable(['branches' => [$branch]]),
        RunGraph::fromRoutePlan($plan, ['worker'], 'completed', [$branch]),
    ] as $graph) {
        $html = renderUsageGraph($graph);
        preg_match_all('/<text[^>]*class="swarm-graph__meta"[^>]*>(.*?)<\/text>/s', $html, $matches);
        expect(array_map(Render::text(...), $matches[1]))->toBe([$label.' tokens'])
            ->and($html)->not->toContain('sw0:', 'must-mask')
            ->toContain('visible', 'unavailable');
    }
})->with(Render::cases());

test('structural route-only pending and empty-group graph nodes are not unknown invocations', function () {
    $plan = ['nodes' => [
        'parallel' => ['type' => 'parallel', 'branches' => []],
        'wait' => ['type' => 'wait'],
        'finish' => ['type' => 'finish'],
        'route-only' => ['type' => 'worker', 'agent' => 'Agent'],
        'pending' => ['type' => 'worker', 'agent' => 'Agent'],
    ]];
    $pending = ['node_id' => 'pending', 'status' => 'pending', 'attempts' => 0, 'usage' => []];
    foreach ([
        RunGraph::fromRun(RunDisplayPresenter::present(['topology' => 'parallel', 'steps' => []])),
        RunGraph::fromRoutePlan($plan, [], 'running', [$pending]),
        RunGraph::fromDurable(['branches' => [$pending]]),
    ] as $graph) {
        expect(renderUsageGraph($graph))->not->toContain('class="swarm-graph__meta"');
    }
    $withInvocation = RunGraph::fromRun(RunDisplayPresenter::present(['topology' => 'parallel', 'steps' => [['agent_class' => 'Agent', 'metadata' => ['usage' => []]]]]));
    $html = renderUsageGraph($withInvocation);
    expect(substr_count($html, 'class="swarm-graph__meta"'))->toBe(1)
        ->and($html)->toContain('Unavailable tokens');
});
