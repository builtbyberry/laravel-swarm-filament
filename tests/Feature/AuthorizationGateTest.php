<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarmFilament\Concerns\AuthorizesSwarmObservability;
use BuiltByBerry\LaravelSwarmFilament\Support\SwarmObservabilityGate;
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

test('defers to Filament authorization when the ability is null or empty', function () {
    config()->set('swarm-filament.authorization.ability', null);
    expect(SwarmObservabilityGate::allows())->toBeTrue();

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
