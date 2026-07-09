<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarmFilament\Concerns\AuthorizesSwarmObservability;
use BuiltByBerry\LaravelSwarmFilament\Pages\SwarmHealthPage;
use BuiltByBerry\LaravelSwarmFilament\Support\SwarmObservabilityGate;
use BuiltByBerry\LaravelSwarmFilament\Widgets\SwarmHealthWidget;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Gate;

/*
 * Component #3 — deny-by-default authorization (design-gate F6). A surface is
 * visible only when the host Gate grants the configured ability to the
 * authenticated user; no definition means denied.
 */

class GateStubResource
{
    use AuthorizesSwarmObservability;
}

class GateStubModel extends Model {}

class GateStubUser extends Authenticatable {}

test('denies by default when the configured ability has no Gate definition', function () {
    $this->actingAs(new GateStubUser);
    config()->set('swarm-filament.authorization.ability', 'viewSwarmObservability');

    expect(SwarmObservabilityGate::allows())->toBeFalse();
});

test('denies when there is no authenticated user, even with a permissive Gate', function () {
    config()->set('swarm-filament.authorization.ability', 'viewSwarmObservability');
    Gate::define('viewSwarmObservability', fn (): bool => true);

    // No actingAs — a panel always authenticates, so no-user is the safest denial.
    expect(SwarmObservabilityGate::allows())->toBeFalse();
});

test('grants when the host Gate allows the ability for the user', function () {
    $this->actingAs(new GateStubUser);
    config()->set('swarm-filament.authorization.ability', 'viewSwarmObservability');
    Gate::define('viewSwarmObservability', fn (): bool => true);

    expect(SwarmObservabilityGate::allows())->toBeTrue();
});

test('denies when the host Gate denies the ability for the user', function () {
    $this->actingAs(new GateStubUser);
    config()->set('swarm-filament.authorization.ability', 'viewSwarmObservability');
    Gate::define('viewSwarmObservability', fn (): bool => false);

    expect(SwarmObservabilityGate::allows())->toBeFalse();
});

test('a null or empty ability turns the gate off — visible to any panel user', function () {
    // Ability off is a grant, not a hand-off to a per-resource policy: allows()
    // returns true regardless of user or Gate state. A bare (no-privilege) user,
    // and even no user at all, is granted — the surface is visible to anyone who
    // can already reach the panel.
    config()->set('swarm-filament.authorization.ability', null);
    expect(SwarmObservabilityGate::allows())->toBeTrue();      // no authenticated user

    $this->actingAs(new GateStubUser);
    expect(SwarmObservabilityGate::allows())->toBeTrue();      // ordinary panel user

    config()->set('swarm-filament.authorization.ability', '');
    expect(SwarmObservabilityGate::allows())->toBeTrue();
});

test('the resource trait mirrors the gate across every authorization hook', function () {
    $this->actingAs(new GateStubUser);
    config()->set('swarm-filament.authorization.ability', 'viewSwarmObservability');

    // Deny-by-default: no Gate definition.
    expect(GateStubResource::canAccess())->toBeFalse()
        ->and(GateStubResource::canViewAny())->toBeFalse()
        ->and(GateStubResource::canView(new GateStubModel))->toBeFalse();

    Gate::define('viewSwarmObservability', fn (): bool => true);

    expect(GateStubResource::canAccess())->toBeTrue()
        ->and(GateStubResource::canViewAny())->toBeTrue()
        ->and(GateStubResource::canView(new GateStubModel))->toBeTrue();
});

test('the shipped config default is the non-empty viewSwarmObservability ability', function () {
    // Deny-by-default for a fresh install rests entirely on the packaged default
    // being a non-empty string — every other test config()->set()s it, so lock
    // the un-overridden default here.
    expect(config('swarm-filament.authorization.ability'))->toBe('viewSwarmObservability');
});

test('the resource trait denies an unauthenticated user even with a permissive gate', function () {
    // The no-user denial must hold through the trait hooks Filament actually calls,
    // not just the raw helper.
    config()->set('swarm-filament.authorization.ability', 'viewSwarmObservability');
    Gate::define('viewSwarmObservability', fn (): bool => true);

    expect(GateStubResource::canAccess())->toBeFalse()
        ->and(GateStubResource::canViewAny())->toBeFalse()
        ->and(GateStubResource::canView(new GateStubModel))->toBeFalse();
});

test('the gate-off branch short-circuits before consulting the gate', function () {
    // With ability=null, a DENYING gate must be irrelevant — proving allows()
    // returns before ever calling Gate::allows() (the gate is off, not deferring).
    $this->actingAs(new GateStubUser);
    config()->set('swarm-filament.authorization.ability', null);
    Gate::define('viewSwarmObservability', fn (): bool => false);

    expect(SwarmObservabilityGate::allows())->toBeTrue();
});

test('with the gate off, the resource trait grants any authenticated user (no Gate needed)', function () {
    // Surface-level proof of the "visible to any panel user" contract: with the
    // ability off, an ordinary user with NO Gate definition passes every Resource
    // authorization hook — the trait grants rather than deferring to a policy.
    $this->actingAs(new GateStubUser);
    config()->set('swarm-filament.authorization.ability', null);

    expect(GateStubResource::canAccess())->toBeTrue()
        ->and(GateStubResource::canViewAny())->toBeTrue()
        ->and(GateStubResource::canView(new GateStubModel))->toBeTrue();
});

/*
 * Component #8 (finding #3-F5) — the health Page and Widget do NOT share the
 * Resource trait: a Page authorizes through `canAccess(): bool` and a Widget
 * through `canView(): bool` (different Filament signatures). Both must apply the
 * same deny-by-default gate, factored into the SwarmPage / SwarmWidget bases so it
 * cannot drift. These are gate tests only — no Livewire render.
 */

test('the health page canAccess denies by default and mirrors the gate', function () {
    $this->actingAs(new GateStubUser);
    config()->set('swarm-filament.authorization.ability', 'viewSwarmObservability');

    // Deny-by-default: no Gate definition.
    expect(SwarmHealthPage::canAccess())->toBeFalse();

    Gate::define('viewSwarmObservability', fn (): bool => true);
    expect(SwarmHealthPage::canAccess())->toBeTrue();

    Gate::define('viewSwarmObservability', fn (): bool => false);
    expect(SwarmHealthPage::canAccess())->toBeFalse();
});

test('the health page canAccess denies an unauthenticated user even with a permissive gate', function () {
    config()->set('swarm-filament.authorization.ability', 'viewSwarmObservability');
    Gate::define('viewSwarmObservability', fn (): bool => true);

    // No actingAs — a panel always authenticates, so no-user is the safest denial.
    expect(SwarmHealthPage::canAccess())->toBeFalse();
});

test('the health widget canView denies by default and mirrors the gate', function () {
    $this->actingAs(new GateStubUser);
    config()->set('swarm-filament.authorization.ability', 'viewSwarmObservability');

    expect(SwarmHealthWidget::canView())->toBeFalse();

    Gate::define('viewSwarmObservability', fn (): bool => true);
    expect(SwarmHealthWidget::canView())->toBeTrue();

    Gate::define('viewSwarmObservability', fn (): bool => false);
    expect(SwarmHealthWidget::canView())->toBeFalse();
});

test('the health widget canView denies an unauthenticated user even with a permissive gate', function () {
    config()->set('swarm-filament.authorization.ability', 'viewSwarmObservability');
    Gate::define('viewSwarmObservability', fn (): bool => true);

    expect(SwarmHealthWidget::canView())->toBeFalse();
});

test('with the gate off, the page and widget are visible to any panel user', function () {
    config()->set('swarm-filament.authorization.ability', null);

    expect(SwarmHealthPage::canAccess())->toBeTrue()
        ->and(SwarmHealthWidget::canView())->toBeTrue();
});
