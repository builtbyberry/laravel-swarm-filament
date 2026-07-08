# Public surface — v0.1.0

The stable, supported API of `builtbyberry/laravel-swarm-filament`. Anything not
listed here (presenters, models, pages, resources, concerns) is an implementation
detail and may change within a minor release.

## Plugin

- `BuiltByBerry\LaravelSwarmFilament\SwarmFilamentPlugin`
  - `SwarmFilamentPlugin::make(): static` — register on a panel: `$panel->plugin(SwarmFilamentPlugin::make())`.
  - `SwarmFilamentPlugin::get(): static` — resolve the registered plugin by id.
  - `getId(): string` — `'laravel-swarm'`.

## Service provider

- `BuiltByBerry\LaravelSwarmFilament\SwarmFilamentServiceProvider` — auto-discovered
  (Laravel package discovery); registers config, views, and the plugin bindings.
  Ships an install command and a publishable config.

## Configuration

Published to `config/swarm-filament.php` via
`php artisan vendor:publish --tag=swarm-filament-config`.

- `navigation.group` (string, default `'Swarm'`) — the Filament navigation group
  every surface is placed under.
- `navigation.sort` (int|null, default `null`) — optional sort order for the group.
- `authorization.ability` (string|null, default `'viewSwarmObservability'`) — the
  Gate ability every surface authorizes against (deny-by-default). Set to `null`
  (or `''`) to defer to Filament's own authorization instead.

## Authorization contract

- The host application grants access by defining the configured Gate ability, e.g.
  `Gate::define('viewSwarmObservability', fn ($user) => $user->is_admin)`. With no
  Gate defined (or no authenticated user), access is denied.
- Extension points for host apps building their own Swarm-scoped surfaces on the
  same deny-by-default gate:
  - `BuiltByBerry\LaravelSwarmFilament\Resources\SwarmResource` (abstract resource base)
  - `BuiltByBerry\LaravelSwarmFilament\Pages\SwarmPage` (abstract page base)
  - `BuiltByBerry\LaravelSwarmFilament\Widgets\SwarmWidget` (abstract widget base)

## Upstream dependency

Reads persisted data only through laravel-swarm v0.19's public read contracts
(`InspectsDurableRuns`, `ReadableRunHistoryStore`, `ReadableAuditOutbox`) and the
app's optional `ReadableSwarmAuditSink`. This package never touches the `@internal`
`SwarmPersistenceCipher` or any operational/strict read path.
