<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Contracts\InspectsDurableRuns;
use BuiltByBerry\LaravelSwarm\Contracts\ReadableAuditOutbox;
use BuiltByBerry\LaravelSwarm\Contracts\ReadableRunHistoryStore;
use BuiltByBerry\LaravelSwarmFilament\SwarmFilamentPlugin;
use Filament\Contracts\Plugin;

test('the service provider boots and registers the package view namespace', function () {
    expect(view()->exists('swarm-filament::__nonexistent__'))->toBeFalse(); // namespace resolves, view absent
    expect(app('view')->getFinder()->getHints())->toHaveKey('swarm-filament');
});

test('the plugin is a valid Filament plugin', function () {
    $plugin = SwarmFilamentPlugin::make();

    expect($plugin)->toBeInstanceOf(Plugin::class)
        ->and($plugin->getId())->toBe('laravel-swarm');
});

test('the v0.19 read-only contracts resolve so the panel can bind them', function () {
    // The whole point: the companion reads through the public seams, never the
    // @internal cipher. In cache mode these bind to the cache/no-op stores.
    expect(app(ReadableRunHistoryStore::class))->toBeInstanceOf(ReadableRunHistoryStore::class)
        ->and(app(ReadableAuditOutbox::class))->toBeInstanceOf(ReadableAuditOutbox::class)
        ->and(app(InspectsDurableRuns::class))->toBeInstanceOf(InspectsDurableRuns::class);
});
