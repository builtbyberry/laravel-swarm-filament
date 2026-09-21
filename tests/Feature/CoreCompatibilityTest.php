<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Contracts\ReadableRunHistoryStore;
use BuiltByBerry\LaravelSwarm\Contracts\RunHistoryStore;
use BuiltByBerry\LaravelSwarm\Contracts\StreamEventStore;
use BuiltByBerry\LaravelSwarm\Responses\SwarmResponse;
use BuiltByBerry\LaravelSwarm\Responses\SwarmStep;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmStreamEvent;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmToolResult;
use BuiltByBerry\LaravelSwarm\Support\RunContext;
use BuiltByBerry\LaravelSwarmFilament\Resources\SwarmRunResource;
use BuiltByBerry\LaravelSwarmFilament\Resources\SwarmRunResource\Pages\ViewSwarmRun;
use BuiltByBerry\LaravelSwarmFilament\Support\StreamTimelinePresenter;
use Composer\InstalledVersions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\View;
use Laravel\Ai\Responses\Data\ToolResult;

beforeEach(function () {
    config()->set([
        'swarm.persistence.driver' => 'database',
        'swarm.persistence.encrypt_at_rest' => true,
        'swarm.capture.inputs' => true,
        'swarm.capture.outputs' => true,
        'swarm.capture.artifacts' => true,
        'swarm.capture.active_context' => true,
    ]);
    Artisan::call('migrate', ['--database' => 'testing']);
});

test('public core writes survive encrypted history reads through the actual detail and list adapters without writes', function () {
    $context = new RunContext('compat-history', '[User] Find the answer.');
    $store = app(RunHistoryStore::class);
    $store->start($context->runId, 'App\\Swarms\\Example', 'sequential', $context, [], 3600);
    $step = new SwarmStep('App\\Agents\\Writer', 'step input', 'step output', metadata: ['index' => 0]);
    $store->recordStep($context->runId, $step, 3600);
    $store->complete($context->runId, new SwarmResponse('final answer', [$step], context: $context), 3600);

    $before = DB::table('swarm_run_histories')->get()->toJson();
    expect($before)->toContain('sw0:')->not->toContain('final answer')->not->toContain('step output');
    DB::connection()->enableQueryLog();
    DB::connection()->flushQueryLog();

    $detail = ViewSwarmRun::resolveDisplay(app(ReadableRunHistoryStore::class), $context->runId);
    $summary = SwarmRunResource::runSummary($context->runId);
    $queries = DB::connection()->getQueryLog();

    expect($detail['run_id'])->toBe($context->runId)
        ->and($detail['status'])->toBe('completed')
        ->and($detail['context'])->toBe('[User] Find the answer.')
        ->and($detail['output'])->toBe('final answer')
        ->and($detail['steps'][0]['input'])->toBe('step input')
        ->and($detail['steps'][0]['output'])->toBe('step output')
        ->and($summary)->toBe('Find the answer.')
        ->and(json_encode($detail))->not->toContain('sw0:')
        ->and($queries)->not->toBeEmpty();
    foreach ($queries as $query) {
        expect(strtolower(ltrim($query['query'])))->toStartWith('select');
    }
    expect(DB::table('swarm_run_histories')->get()->toJson())->toBe($before);
});

test('persisted typed tool results retain their lane semantics and causal corrections in the rendered timeline', function (string $outcome) {
    $adoption = version_compare(InstalledVersions::getVersion('builtbyberry/laravel-swarm'), '0.26.0.0', '>=');
    $runId = 'compat-stream-'.$outcome;
    app(RunHistoryStore::class)->start($runId, 'App\\Swarms\\Example', 'sequential', new RunContext($runId, 'task'), [], 3600);
    $arguments = ['id' => 'call-1', 'name' => 'lookup', 'arguments' => ['nested' => ['secret' => 'sw0:masked-argument', 'safe' => 'visible']], 'result' => ['secret' => 'sw0:masked-result', 'safe' => 'result visible'], 'resultId' => 'result-1'];
    if ($adoption) {
        $arguments['denied'] = $outcome === 'denied';
        $arguments['failed'] = $outcome === 'failed';
    }
    $event = new SwarmToolResult('tool-1', $runId, 0, 'App\\Agents\\Writer', new ToolResult(...$arguments), $outcome === 'success', $outcome === 'success' ? null : '[redacted]', 100);
    $event->withNodeId('node-1')->withInvocationId('invocation-1');
    $store = app(StreamEventStore::class);
    $store->record($runId, $event, 3600);
    $store->record($runId, SwarmStreamEvent::fromArray([
        'id' => 'void-1', 'type' => 'swarm_causal_void_edge', 'run_id' => $runId,
        'target_event_id' => 'tool-1', 'void_type' => 'supersedes', 'reason' => 'new attempt', 'node_id' => 'node-1', 'timestamp' => 101,
    ]), 3600);
    $before = DB::table('swarm_stream_events')->orderBy('id')->get()->toJson();
    DB::connection()->enableQueryLog();
    DB::connection()->flushQueryLog();

    $events = iterator_to_array($store->events($runId));
    $timeline = StreamTimelinePresenter::present($events);
    $html = html_entity_decode(View::make('swarm-filament::timeline', ['timeline' => $timeline])->render(), ENT_QUOTES | ENT_HTML5);
    $queries = DB::connection()->getQueryLog();
    $payload = json_decode($timeline['nodes'][0]['events'][0]['payload'], true, 512, JSON_THROW_ON_ERROR);
    $raw = $events[0]->toArray();

    expect($events)->toHaveCount(2)
        ->and($events[0])->toBeInstanceOf(SwarmToolResult::class)
        ->and($raw['id'])->toBe('tool-1')
        ->and($raw['invocation_id'])->toBe('invocation-1')
        ->and($raw['node_id'])->toBe('node-1')
        ->and($raw['timestamp'])->toBe(100)
        ->and($payload['successful'])->toBe($outcome === 'success')
        ->and($payload['error'])->toBe($outcome === 'success' ? null : '[redacted]')
        ->and($payload['tool_result']['name'])->toBe('lookup')
        ->and($payload['tool_result']['result_id'])->toBe('result-1')
        ->and($payload['tool_result']['arguments']['nested'])->toBe(['secret' => 'unavailable', 'safe' => 'visible'])
        ->and($payload['tool_result']['result'])->toBe(['secret' => 'unavailable', 'safe' => 'result visible'])
        ->and($timeline['event_count'])->toBe(1)
        ->and($timeline['void_edge_count'])->toBe(1)
        ->and($timeline['nodes'][0]['events'][0]['marker'])->toBe('Voided · superseded — new attempt')
        ->and($timeline['nodes'][0]['events'][1]['event_id'])->toBe('void-1')
        ->and($html)->toContain('Tool result', 'Void edge', 'Voids tool-1', 'swarm-tl__chip--marker', '#d97706')
        ->not->toContain('sw0:', 'masked-argument', 'masked-result');

    if ($adoption) {
        expect($payload['tool_result']['denied'] ?? false)->toBe($outcome === 'denied')
            ->and($payload['tool_result']['failed'] ?? false)->toBe($outcome === 'failed');
        // The timeline view consumes structural chips; outcome flags stay in its presenter payload.
        expect($timeline['nodes'][0]['events'][0]['summary'])->toBe('Tool result');
    } else {
        // Legacy readers predate the candidate's native outcome preservation.
        expect($payload['tool_result'])->not->toHaveKey('denied')->not->toHaveKey('failed');
    }
    foreach ($queries as $query) {
        expect(strtolower(ltrim($query['query'])))->toStartWith('select');
    }
    expect(DB::table('swarm_stream_events')->orderBy('id')->get()->toJson())->toBe($before);
})->with(['success', 'denied', 'failed']);
