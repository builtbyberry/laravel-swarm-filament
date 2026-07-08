<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Audit\NoOpSwarmAuditSink;
use BuiltByBerry\LaravelSwarm\Contracts\AuditOutbox;
use BuiltByBerry\LaravelSwarm\Contracts\ReadableAuditOutbox;
use BuiltByBerry\LaravelSwarm\Contracts\ReadableSwarmAuditSink;
use BuiltByBerry\LaravelSwarm\Contracts\SwarmAuditSink;
use BuiltByBerry\LaravelSwarm\Persistence\SwarmPersistenceCipher;
use BuiltByBerry\LaravelSwarmFilament\Pages\AuditOutboxHealth;
use BuiltByBerry\LaravelSwarmFilament\Pages\AuditOutboxRecord;
use BuiltByBerry\LaravelSwarmFilament\Pages\AuditTrace;
use BuiltByBerry\LaravelSwarmFilament\Support\AuditTracePresenter;
use BuiltByBerry\LaravelSwarmFilament\Support\OutboxHealthPresenter;
use Illuminate\Config\Repository;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Encryption\Encrypter;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Psr\Log\NullLogger;

class AuditGateUser extends Authenticatable {}

/*
 * Component #10 — the audit surfaces. Two read-only lanes over the public v0.19
 * contracts: (a) outbox health, a NON-consuming index (pure SELECT, coexists with
 * the swarm:relay drainer) that is payload-minimized (metadata + last_error only),
 * with a single-row payload detail decrypted on demand; and (b) the audit-trace
 * timeline, sourced from the app's ReadableSwarmAuditSink or a clean empty-state.
 * No sw0: ciphertext ever reaches a rendered cell. Records 629/632.
 */

/** A sealed sw0: value encrypted under a foreign key — undecryptable here, so it degrades. */
function auditPoison(string $plaintext = 'unreadable'): string
{
    $foreign = new SwarmPersistenceCipher(
        new Repository(['swarm.persistence.encrypt_at_rest' => true, 'swarm.persistence.driver' => 'database']),
        new Encrypter(random_bytes(32), 'aes-256-cbc'),
        new NullLogger,
    );

    return (string) $foreign->seal($plaintext);
}

/** Switch persistence to the database driver and rebind the real outbox over it. */
function auditUseDatabase(): ReadableAuditOutbox
{
    config()->set('swarm.persistence.driver', 'database');
    config()->set('swarm.persistence.encrypt_at_rest', true);
    app()->forgetInstance(AuditOutbox::class);
    app()->forgetInstance(ReadableAuditOutbox::class);
    app()->forgetInstance(SwarmPersistenceCipher::class);
    Artisan::call('migrate', ['--database' => 'testing']);

    return app(ReadableAuditOutbox::class);
}

/** A ReadableSwarmAuditSink whose forRun() is scripted. */
function auditReadableSink(array $records, ?Throwable $throws = null): ReadableSwarmAuditSink
{
    return new class($records, $throws) implements ReadableSwarmAuditSink
    {
        public function __construct(private array $records, private ?Throwable $throws) {}

        public function emit(string $category, array $payload): void {}

        public function forRun(string $runId): iterable
        {
            if ($this->throws !== null) {
                throw $this->throws;
            }

            return $this->records;
        }
    };
}

// ---------------------------------------------------------------------------
// OutboxHealthPresenter — the single leak-proof, payload-minimized mapping.
// ---------------------------------------------------------------------------

test('the outbox presenter maps the non-consuming health summary', function () {
    $presented = OutboxHealthPresenter::present(
        ['available' => true, 'pending' => 4, 'dead_letter' => 2, 'reserved' => 1, 'oldest_pending_at' => '2026-07-08 00:00:00'],
        [],
        [],
    );

    expect($presented['available'])->toBeTrue()
        ->and($presented['pending_count'])->toBe(4)
        ->and($presented['dead_letter_count'])->toBe(2)
        ->and($presented['reserved_count'])->toBe(1)
        ->and($presented['oldest_pending_at'])->toBe('2026-07-08 00:00:00');
});

test('the outbox presenter maps list rows to metadata + last_error only, never the payload', function () {
    $presented = OutboxHealthPresenter::present(
        ['available' => true, 'pending' => 1, 'dead_letter' => 0, 'reserved' => 0, 'oldest_pending_at' => null],
        [[
            'id' => 7,
            'category' => 'run.started',
            'run_id' => 'run-1',
            'status' => 'pending',
            'attempts' => 2,
            'last_error' => 'connection refused',
            'last_error_available' => true,
            'created_at' => '2026-07-08 00:00:00',
            // Even if a caller wrongly included a payload, the list mapping drops it.
            'payload' => ['secret' => 'do not surface'],
            'payload_available' => true,
        ]],
        [],
    );

    $row = $presented['pending'][0];

    expect($row['id'])->toBe(7)
        ->and($row['category'])->toBe('run.started')
        ->and($row['run_id'])->toBe('run-1')
        ->and($row['status'])->toBe('pending')
        ->and($row['attempts'])->toBe(2)
        ->and($row['last_error'])->toBe('connection refused')
        // Payload minimization: no payload key ever leaves the list mapping.
        ->and($row)->not->toHaveKey('payload')
        ->and(json_encode($presented))->not->toContain('do not surface');
});

test('the outbox presenter degrades an undecryptable last_error, never leaking ciphertext', function () {
    $presented = OutboxHealthPresenter::present(
        ['available' => true, 'pending' => 1, 'dead_letter' => 0, 'reserved' => 0, 'oldest_pending_at' => null],
        [
            ['id' => 1, 'category' => 'a', 'status' => 'pending', 'attempts' => 0, 'last_error' => null, 'last_error_available' => false],
            ['id' => 2, 'category' => 'b', 'status' => 'pending', 'attempts' => 0, 'last_error' => null, 'last_error_available' => true],
            ['id' => 3, 'category' => 'c', 'status' => 'pending', 'attempts' => 0, 'last_error' => 'sw0:leaked', 'last_error_available' => true],
        ],
        [],
    );

    expect($presented['pending'][0]['last_error'])->toBe('unavailable')
        ->and($presented['pending'][1]['last_error'])->toBe('none')
        // Defense in depth: a still-sealed value is masked even when flagged available.
        ->and($presented['pending'][2]['last_error'])->toBe('unavailable')
        ->and(json_encode($presented))->not->toContain('sw0:');
});

test('the outbox presenter tolerates a malformed row', function () {
    $presented = OutboxHealthPresenter::present(
        ['available' => false],
        ['not-an-array', ['id' => 5, 'category' => 'x', 'status' => 'pending']],
        [],
    );

    expect($presented['pending'])->toHaveCount(1)
        ->and($presented['pending'][0]['id'])->toBe(5)
        ->and($presented['available'])->toBeFalse()
        ->and($presented['pending_count'])->toBe(0);
});

test('the outbox detail presenter renders the decrypted payload on demand', function () {
    $presented = OutboxHealthPresenter::presentRecord([
        'id' => 9,
        'category' => 'run.started',
        'run_id' => 'run-9',
        'status' => 'pending',
        'attempts' => 0,
        'last_error' => null,
        'last_error_available' => true,
        'payload' => ['run_id' => 'run-9', 'detail' => 'the secret detail'],
        'payload_available' => true,
    ]);

    expect($presented['id'])->toBe(9)
        ->and($presented['payload'])->toContain('the secret detail')
        ->and($presented['last_error'])->toBe('none');
});

test('the outbox detail presenter degrades an undecryptable payload to unavailable', function () {
    $presented = OutboxHealthPresenter::presentRecord([
        'id' => 9, 'category' => 'run.started', 'status' => 'dead_letter', 'attempts' => 5,
        'payload' => null, 'payload_available' => false,
    ]);

    expect($presented['payload'])->toBe('unavailable');
});

test('the outbox detail presenter distinguishes an empty payload (none) from an unavailable one', function () {
    $presented = OutboxHealthPresenter::presentRecord([
        'id' => 9, 'category' => 'run.started', 'status' => 'pending', 'attempts' => 0,
        'payload' => null, 'payload_available' => true,
    ]);

    expect($presented['payload'])->toBe('none');
});

// ---------------------------------------------------------------------------
// AuditOutboxRecord::resolveRecord — the unknown-id → 404 guard.
// ---------------------------------------------------------------------------

test('the outbox detail resolve 404s when the id is unknown', function () {
    // A contract whose record() returns null (unknown id, or unavailable outbox)
    // must 404 rather than render an empty shell — tested below the render layer.
    $outbox = new class implements ReadableAuditOutbox
    {
        public function isAvailable(): bool
        {
            return true;
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
            return ['available' => true, 'pending' => 0, 'dead_letter' => 0, 'reserved' => 0, 'oldest_pending_at' => null];
        }
    };

    AuditOutboxRecord::resolveRecord($outbox, 999999);
})->throws(ModelNotFoundException::class);

// ---------------------------------------------------------------------------
// AuditTracePresenter — sink classification, empty-state, degrade-safety.
// ---------------------------------------------------------------------------

test('the trace presenter classifies the no-op sink as unreadable and yields the bind-a-sink empty-state', function () {
    $presented = AuditTracePresenter::present(new NoOpSwarmAuditSink, 'run-1');

    expect($presented['readable'])->toBeFalse()
        ->and($presented['reason'])->toBe('noop')
        ->and($presented['records'])->toBe([])
        ->and($presented['notes'][0])->toContain('ReadableSwarmAuditSink');
});

test('the trace presenter classifies a non-readable sink and explains the limitation', function () {
    $sink = new class implements SwarmAuditSink
    {
        public function emit(string $category, array $payload): void {}
    };

    $presented = AuditTracePresenter::present($sink, 'run-1');

    expect($presented['readable'])->toBeFalse()
        ->and($presented['reason'])->toBe('not_readable')
        ->and($presented['records'])->toBe([])
        ->and($presented['notes'][0])->toContain('does not implement ReadableSwarmAuditSink');
});

test('the trace presenter maps and sorts a readable sink timeline, omitting payloads', function () {
    $sink = auditReadableSink([
        ['category' => 'step.completed', 'occurred_at' => '2026-07-08T00:00:02Z', 'run_id' => 'run-1', 'payload' => ['secret' => 'nope']],
        ['category' => 'run.started', 'occurred_at' => '2026-07-08T00:00:01Z', 'run_id' => 'run-1'],
        'not-an-array',
    ]);

    $presented = AuditTracePresenter::present($sink, 'run-1');

    expect($presented['readable'])->toBeTrue()
        ->and($presented['reason'])->toBe('readable')
        ->and($presented['records'])->toHaveCount(2)
        // Sorted by occurred_at ascending.
        ->and($presented['records'][0]['category'])->toBe('run.started')
        ->and($presented['records'][1]['category'])->toBe('step.completed')
        // Payload minimization: the timeline never carries the evidence envelope.
        ->and($presented['records'][0])->not->toHaveKey('payload')
        ->and(json_encode($presented))->not->toContain('nope');
});

test('the trace presenter degrades a throwing sink to a note, never a 500', function () {
    $sink = auditReadableSink([], new RuntimeException('sink is down'));

    $presented = AuditTracePresenter::present($sink, 'run-1');

    expect($presented['records'])->toBe([])
        ->and($presented['error'])->toBe('sink is down')
        ->and(implode(' ', $presented['notes']))->toContain('sink is down');
});

test('the trace presenter prompts for a run id when a readable sink has no run', function () {
    $presented = AuditTracePresenter::present(auditReadableSink([]), null);

    expect($presented['run_id'])->toBeNull()
        ->and($presented['records'])->toBe([])
        ->and($presented['notes'][0])->toContain('Provide a run id');
});

test('the trace presenter truncates at the read limit', function () {
    $records = array_map(
        static fn (int $i): array => ['category' => "c{$i}", 'occurred_at' => sprintf('2026-07-08T00:00:%02dZ', $i)],
        range(0, 5),
    );

    $presented = AuditTracePresenter::present(auditReadableSink($records), 'run-1', limit: 3);

    expect($presented['records'])->toHaveCount(3)
        ->and($presented['truncated'])->toBeTrue()
        ->and(implode(' ', $presented['notes']))->toContain('truncated');
});

test('the trace resolve wires the bound sink — default is the no-op empty-state', function () {
    // Core's default binding is NoOpSwarmAuditSink, so out of the box the page
    // renders the clean bind-a-sink empty-state.
    $presented = AuditTrace::resolve(app(SwarmAuditSink::class), 'run-1');

    expect($presented['reason'])->toBe('noop')
        ->and($presented['readable'])->toBeFalse();
});

// ---------------------------------------------------------------------------
// End-to-end data path: the real ReadableAuditOutbox contract.
// ---------------------------------------------------------------------------

test('the outbox health page reads the real contract, non-consuming and payload-minimized', function () {
    $outbox = auditUseDatabase();

    // Prove list-side last_error masking end-to-end (not just via the presenter
    // unit): a sealed, undecryptable last_error must degrade in the real read path.
    config()->set('swarm.persistence.decrypt_failure_policy', 'throw');

    // Seed via the real outbox (it also implements AuditOutbox::enqueue).
    $outbox->enqueue('run.started', ['run_id' => 'run-1', 'detail' => 'first']);
    $outbox->enqueue('step.completed', ['run_id' => 'run-2', 'detail' => 'second']);
    $outbox->enqueue('run.failed', ['run_id' => 'run-3', 'detail' => 'boom'], deadLetter: true);

    // Poison the newest pending row's last_error with a foreign-key sealed value.
    DB::table('swarm_audit_outbox')->where('run_id', 'run-2')->update(['last_error' => auditPoison('exploded')]);

    $presented = AuditOutboxHealth::resolve($outbox);

    expect($presented['available'])->toBeTrue()
        ->and($presented['pending_count'])->toBe(2)
        ->and($presented['dead_letter_count'])->toBe(1)
        ->and($presented['pending'])->toHaveCount(2)
        // Newest first, metadata + last_error only — never the payload.
        ->and($presented['pending'][0]['category'])->toBe('step.completed')
        ->and($presented['pending'][0])->not->toHaveKey('payload')
        // The poison last_error degrades in the real LIST path, never leaking sw0:.
        ->and($presented['pending'][0]['last_error'])->toBe('unavailable')
        ->and($presented['dead_lettered'][0]['category'])->toBe('run.failed');

    // No decrypted evidence detail ('first'/'second'/'boom') leaks into the list,
    // and no ciphertext survives anywhere in the presented health data.
    expect(json_encode($presented))->not->toContain('first')
        ->and(json_encode($presented))->not->toContain('boom')
        ->and(json_encode($presented))->not->toContain('sw0:');

    // Read again — a pure SELECT must never reserve or delete the drainer's rows.
    AuditOutboxHealth::resolve($outbox);

    expect(DB::table('swarm_audit_outbox')->count())->toBe(3)
        ->and(DB::table('swarm_audit_outbox')->whereNotNull('reserved_at')->count())->toBe(0);
});

test('the outbox record detail decrypts the full payload on demand through the real contract', function () {
    $outbox = auditUseDatabase();
    $outbox->enqueue('run.started', ['run_id' => 'run-9', 'detail' => 'the secret detail']);
    $id = (int) DB::table('swarm_audit_outbox')->where('run_id', 'run-9')->value('id');

    $presented = AuditOutboxRecord::resolveRecord($outbox, $id);

    expect($presented['run_id'])->toBe('run-9')
        ->and($presented['payload'])->toContain('the secret detail');
});

test('the outbox record detail degrades a poison payload under the throw policy, never leaking sw0:', function () {
    $outbox = auditUseDatabase();
    config()->set('swarm.persistence.decrypt_failure_policy', 'throw');

    $outbox->enqueue('run.started', ['run_id' => 'bad', 'detail' => 'unreadable']);
    $id = (int) DB::table('swarm_audit_outbox')->where('run_id', 'bad')->value('id');
    DB::table('swarm_audit_outbox')->where('id', $id)->update(['payload' => auditPoison()]);

    $presented = AuditOutboxRecord::resolveRecord($outbox, $id);

    expect($presented['payload'])->toBe('unavailable')
        ->and(json_encode($presented))->not->toContain('sw0:');
});

test('an unknown outbox id 404s through the real contract, never a 500', function () {
    $outbox = auditUseDatabase();

    expect(fn () => AuditOutboxRecord::resolveRecord($outbox, 424242))
        ->toThrow(ModelNotFoundException::class);
});

test('the outbox health page renders a clean empty-state when the outbox is unavailable', function () {
    // Cache driver → the contract binds the no-op outbox: unavailable, empty lists.
    config()->set('swarm.persistence.driver', 'cache');
    app()->forgetInstance(AuditOutbox::class);
    app()->forgetInstance(ReadableAuditOutbox::class);

    $presented = AuditOutboxHealth::resolve(app(ReadableAuditOutbox::class));

    expect($presented['available'])->toBeFalse()
        ->and($presented['pending_count'])->toBe(0)
        ->and($presented['dead_letter_count'])->toBe(0)
        ->and($presented['pending'])->toBe([])
        ->and($presented['dead_lettered'])->toBe([]);
});

// ---------------------------------------------------------------------------
// Page shape: read-only, config-driven navigation, deny-by-default access.
// ---------------------------------------------------------------------------

test('every audit page denies by default when the ability has no Gate definition', function () {
    $this->actingAs(new AuditGateUser);
    config()->set('swarm-filament.authorization.ability', 'viewSwarmObservability');

    expect(AuditOutboxHealth::canAccess())->toBeFalse()
        ->and(AuditOutboxRecord::canAccess())->toBeFalse()
        ->and(AuditTrace::canAccess())->toBeFalse();
});

test('every audit page grants when the host Gate allows the ability', function () {
    // The record page (the payload-evidence surface) MUST be reachable under a
    // granted gate — a hardcoded false here would lock operators out silently.
    $this->actingAs(new AuditGateUser);
    config()->set('swarm-filament.authorization.ability', 'viewSwarmObservability');
    Gate::define('viewSwarmObservability', fn (): bool => true);

    expect(AuditOutboxHealth::canAccess())->toBeTrue()
        ->and(AuditOutboxRecord::canAccess())->toBeTrue()
        ->and(AuditTrace::canAccess())->toBeTrue();
});

test('every audit page denies when the host Gate denies the ability', function () {
    $this->actingAs(new AuditGateUser);
    config()->set('swarm-filament.authorization.ability', 'viewSwarmObservability');
    Gate::define('viewSwarmObservability', fn (): bool => false);

    expect(AuditOutboxHealth::canAccess())->toBeFalse()
        ->and(AuditOutboxRecord::canAccess())->toBeFalse()
        ->and(AuditTrace::canAccess())->toBeFalse();
});

test('every audit page denies an unauthenticated user even with a permissive Gate', function () {
    config()->set('swarm-filament.authorization.ability', 'viewSwarmObservability');
    Gate::define('viewSwarmObservability', fn (): bool => true);

    // No actingAs — a panel always authenticates, so no-user is the safest denial.
    expect(AuditOutboxHealth::canAccess())->toBeFalse()
        ->and(AuditOutboxRecord::canAccess())->toBeFalse()
        ->and(AuditTrace::canAccess())->toBeFalse();
});

test('every audit page defers to Filament authorization when the ability is null', function () {
    config()->set('swarm-filament.authorization.ability', null);

    expect(AuditOutboxHealth::canAccess())->toBeTrue()
        ->and(AuditOutboxRecord::canAccess())->toBeTrue()
        ->and(AuditTrace::canAccess())->toBeTrue();
});

test('the outbox record detail is hidden from navigation', function () {
    expect(AuditOutboxRecord::shouldRegisterNavigation())->toBeFalse();
});

test('the audit page navigation group and sort are config-driven', function () {
    expect(AuditOutboxHealth::getNavigationGroup())->toBe('Swarm')
        ->and(AuditTrace::getNavigationGroup())->toBe('Swarm')
        ->and(AuditOutboxHealth::getNavigationSort())->toBeNull();

    config()->set('swarm-filament.navigation.group', 'Observability');
    config()->set('swarm-filament.navigation.sort', 5);

    expect(AuditOutboxHealth::getNavigationGroup())->toBe('Observability')
        ->and(AuditOutboxHealth::getNavigationSort())->toBe(5);

    config()->set('swarm-filament.navigation.sort', 'oops');
    expect(AuditOutboxHealth::getNavigationSort())->toBeNull();
});

test('the shared outbox status color maps each status to a Filament token', function () {
    // Lives on the presenter so the list and the detail badge share one mapping.
    expect(OutboxHealthPresenter::statusColor('pending'))->toBe('warning')
        ->and(OutboxHealthPresenter::statusColor('dead_letter'))->toBe('danger')
        ->and(OutboxHealthPresenter::statusColor('anything'))->toBe('gray')
        ->and(OutboxHealthPresenter::statusColor(null))->toBe('gray');
});

test('the outbox health and record pages register under distinct slugs and route names', function () {
    // Filament derives the route NAME from the slug; a shared slug would collide
    // the two pages' route names and make getUrl() ambiguous (one clobbers the
    // other). Their slugs — and therefore relative route names — must differ.
    expect(AuditOutboxRecord::getSlug())->not->toBe(AuditOutboxHealth::getSlug())
        ->and(AuditOutboxRecord::getSlug())->toBe('audit-outbox-record')
        ->and(AuditOutboxHealth::getSlug())->toBe('audit-outbox');
});
