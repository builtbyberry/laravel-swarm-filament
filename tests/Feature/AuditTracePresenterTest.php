<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Audit\NoOpSwarmAuditSink;
use BuiltByBerry\LaravelSwarm\Contracts\ReadableSwarmAuditSink;
use BuiltByBerry\LaravelSwarm\Contracts\SwarmAuditSink;
use BuiltByBerry\LaravelSwarmFilament\Support\AuditTracePresenter;

/*
 * AuditTracePresenter — the pure audit-trace fold over a bound SwarmAuditSink
 * (audit F7). Exercises the three sink conditions (noop / not_readable / readable)
 * and the degrade branches: an undecryptable/throwing sink, a truncated read, and
 * payload minimization — every case must degrade safely and never leak the evidence
 * envelope. Tested directly with scripted sinks, below the Livewire render layer.
 */

// A real sink that does NOT implement ReadableSwarmAuditSink.
function auditNotReadableSink(): SwarmAuditSink
{
    return new class implements SwarmAuditSink
    {
        public function emit(string $category, array $payload): void {}
    };
}

// A readable sink whose forRun() yields a scripted set of records.
/**
 * @param  list<mixed>  $records
 */
function auditReadableSink(array $records): ReadableSwarmAuditSink
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

// A readable sink whose forRun() throws — a genuinely degraded condition.
function auditThrowingSink(): ReadableSwarmAuditSink
{
    return new class implements ReadableSwarmAuditSink
    {
        public function emit(string $category, array $payload): void {}

        public function forRun(string $runId): iterable
        {
            throw new RuntimeException('sink unavailable');
        }
    };
}

// --- classify(): the three sink conditions --------------------------------

test('classify distinguishes noop, not_readable, and readable sinks without consuming records', function () {
    expect(AuditTracePresenter::classify(new NoOpSwarmAuditSink)['reason'])->toBe('noop');
    expect(AuditTracePresenter::classify(auditNotReadableSink())['reason'])->toBe('not_readable');
    expect(AuditTracePresenter::classify(auditReadableSink([]))['reason'])->toBe('readable');
});

// --- Degrade branch: the no-op (default) sink -----------------------------

test('the default no-op sink degrades to a clean empty-state that explains how to bind a readable sink', function () {
    $trace = AuditTracePresenter::present(new NoOpSwarmAuditSink, 'run-1');

    expect($trace['readable'])->toBeFalse()
        ->and($trace['reason'])->toBe('noop')
        ->and($trace['records'])->toBe([])
        ->and($trace['error'])->toBeNull()
        ->and($trace['notes'][0])->toContain('No readable audit sink is bound');
});

// --- Degrade branch: a real but not-readable sink -------------------------

test('a real sink that cannot list records degrades with a note naming the sink class', function () {
    $sink = auditNotReadableSink();
    $trace = AuditTracePresenter::present($sink, 'run-1');

    expect($trace['readable'])->toBeFalse()
        ->and($trace['reason'])->toBe('not_readable')
        ->and($trace['records'])->toBe([])
        ->and($trace['notes'][0])->toContain($sink::class)
        ->and($trace['notes'][0])->toContain('does not implement ReadableSwarmAuditSink');
});

// --- Readable path + payload minimization (never leak) --------------------

test('a readable sink surfaces category/timestamp/run only and never leaks the evidence envelope', function () {
    $sink = auditReadableSink([
        ['category' => 'run.started', 'occurred_at' => '2026-07-09T10:00:00Z', 'run_id' => 'run-1', 'payload' => ['secret' => 'do-not-leak']],
    ]);

    $trace = AuditTracePresenter::present($sink, 'run-1');

    expect($trace['readable'])->toBeTrue()
        ->and($trace['records'])->toHaveCount(1);

    $record = $trace['records'][0];
    expect($record)->toBe(['category' => 'run.started', 'occurred_at' => '2026-07-09T10:00:00Z', 'run_id' => 'run-1'])
        ->and($record)->not->toHaveKey('payload'); // payload minimization — the envelope is dropped

    // Defense in depth: the leaked value appears nowhere in the whole record.
    expect(json_encode($trace))->not->toContain('do-not-leak');
});

test('readable records sort by occurred_at ascending, with timestamp-less records last', function () {
    $sink = auditReadableSink([
        ['category' => 'c', 'occurred_at' => '2026-07-09T12:00:00Z'],
        ['category' => 'a', 'occurred_at' => '2026-07-09T10:00:00Z'],
        ['category' => 'z'], // no occurred_at → sorts last
        ['category' => 'b', 'occurred_at' => '2026-07-09T11:00:00Z'],
    ]);

    $trace = AuditTracePresenter::present($sink, 'run-1');

    expect(array_column($trace['records'], 'category'))->toBe(['a', 'b', 'c', 'z'])
        ->and($trace['records'][3]['occurred_at'])->toBeNull();
});

test('non-array records from the sink are skipped safely', function () {
    $sink = auditReadableSink([
        ['category' => 'ok', 'occurred_at' => '2026-07-09T10:00:00Z'],
        'a-bare-string',
        42,
    ]);

    $trace = AuditTracePresenter::present($sink, 'run-1');

    expect($trace['records'])->toHaveCount(1)
        ->and($trace['records'][0]['category'])->toBe('ok');
});

test('a record missing category degrades to "unknown" rather than throwing', function () {
    $sink = auditReadableSink([['occurred_at' => '2026-07-09T10:00:00Z']]);

    expect(AuditTracePresenter::present($sink, 'run-1')['records'][0]['category'])->toBe('unknown');
});

// --- Degrade branch: a throwing sink --------------------------------------

test('a forRun() that throws is caught and surfaced as a note — the page never 500s', function () {
    $trace = AuditTracePresenter::present(auditThrowingSink(), 'run-1');

    expect($trace['readable'])->toBeTrue()
        ->and($trace['records'])->toBe([])
        ->and($trace['error'])->toBe('sink unavailable')
        ->and(implode(' ', $trace['notes']))->toContain('The audit sink failed while reading this run');
});

// --- Degrade branch: truncation -------------------------------------------

test('a read past the limit truncates and surfaces the truncation note', function () {
    $sink = auditReadableSink([
        ['category' => 'a', 'occurred_at' => '2026-07-09T10:00:00Z'],
        ['category' => 'b', 'occurred_at' => '2026-07-09T11:00:00Z'],
        ['category' => 'c', 'occurred_at' => '2026-07-09T12:00:00Z'],
    ]);

    $trace = AuditTracePresenter::present($sink, 'run-1', limit: 2);

    expect($trace['records'])->toHaveCount(2)
        ->and($trace['truncated'])->toBeTrue()
        ->and(implode(' ', $trace['notes']))->toContain('truncated');
});

// --- Run-id normalization -------------------------------------------------

test('a blank/whitespace run id is normalized to null and prompts for a run id, reading nothing', function () {
    // Even a readable sink is not consulted without a run id.
    $sink = auditReadableSink([['category' => 'a', 'occurred_at' => '2026-07-09T10:00:00Z']]);

    $trace = AuditTracePresenter::present($sink, '   ');

    expect($trace['run_id'])->toBeNull()
        ->and($trace['records'])->toBe([])
        ->and(implode(' ', $trace['notes']))->toContain('Provide a run id');
});
