<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Memory\MemorySnapshot;
use BuiltByBerry\LaravelSwarmFilament\Support\MemorySnapshotPresenter;

/*
 * MemorySnapshotPresenter is reused on the run page (the per-step memory facet) but
 * lost its test file when the standalone Memory viewer was retired. It carries its
 * own defense-in-depth `sw0:` masking beyond the DisplayField primitive: a nested
 * ciphertext leaf must never reach rendered output.
 *
 * (The StreamTimeline and Audit presenters' leak/degrade paths are covered by
 * StreamTimelinePresenterTest and AuditTracePresenterTest respectively.)
 */

test('memory snapshot masks sealed values and never emits sw0: ciphertext', function () {
    $entry = fn (string $k, mixed $v): array => ['scope' => 'run', 'scope_id' => 'r', 'key' => $k, 'value' => $v];

    $snapshot = new MemorySnapshot(
        runId: 'run-1',
        stepIndex: 0,
        entries: [
            $entry('clean', 'hello'),
            $entry('sealed', 'sw0:AAAABBBBCCCC'),            // top-level ciphertext
            $entry('nested', ['inner' => 'sw0:DEADBEEF']),   // nested ciphertext (json would leak it)
            $entry('redacted', '[redacted]'),                // write-time redaction marker
            $entry('absent', null),                          // not captured
        ],
        toolCalls: [
            ['name' => 'search', 'arguments' => 'sw0:SEALEDARGS', 'result' => 'ok'],
        ],
    );

    $presented = MemorySnapshotPresenter::present($snapshot);
    $byKey = collect($presented['entries'])->keyBy('key');

    expect($byKey['clean']['value'])->toBe('hello')
        ->and($byKey['sealed']['value'])->toBe('unavailable')
        ->and($byKey['nested']['value'])->toBe('unavailable')   // containsSealed degrades the whole value
        ->and($byKey['redacted']['value'])->toBe('[redacted]')
        ->and($byKey['absent']['value'])->toBe('none')          // not-captured is distinct from redacted
        ->and($presented['tool_calls'][0]['arguments'])->toBe('unavailable')
        // the decisive guard: no ciphertext anywhere in the serialized output
        ->and(json_encode($presented))->not->toContain('sw0:');
});
