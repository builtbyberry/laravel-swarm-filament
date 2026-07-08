# Laravel Swarm — Filament

Free, read-only [Filament](https://filamentphp.com) observability panel for [Laravel Swarm](https://github.com/builtbyberry/laravel-swarm) — inspect swarm runs, steps, durable state, memory, streaming, and audit-outbox health directly from your app's Filament panel.

> **View-only by design.** This package surfaces run data through Swarm's public read-only contracts (`InspectsDurableRuns`, `ReadableRunHistoryStore`, `ReadableAuditOutbox`, added in laravel-swarm v0.19). Every sealed field is display-decrypted per row — honoring `swarm.persistence.decrypt_failure_policy`, degrading an undecryptable value to a marked "unavailable" rather than throwing or leaking ciphertext — and no read ever mutates state. Operator control (pause/resume/cancel) lives in the separate paid operator console.

## Requirements

- PHP 8.5+
- Filament 5.x
- `builtbyberry/laravel-swarm` ^0.19

## Installation

```bash
composer require builtbyberry/laravel-swarm-filament
```

Add the plugin to a Filament panel:

```php
use BuiltByBerry\LaravelSwarmFilament\SwarmFilamentPlugin;

public function panel(Panel $panel): Panel
{
    return $panel->plugin(SwarmFilamentPlugin::make());
}
```

Optionally publish the config to customize the navigation group/sort and the
authorization ability:

```bash
php artisan vendor:publish --tag=swarm-filament-config
```

## Authorization

Access is **deny-by-default**. Every surface — resources, pages, and widgets —
authorizes against a configurable [Gate](https://laravel.com/docs/authorization#gates)
ability before it renders or appears in navigation:

```php
// config/swarm-filament.php
'authorization' => [
    'ability' => 'viewSwarmObservability',
],
```

**You must define that Gate in your application to grant access.** Absent a Gate
definition (or an authenticated user), the ability is denied and the surfaces stay
hidden:

```php
use Illuminate\Support\Facades\Gate;

Gate::define('viewSwarmObservability', fn ($user) => $user->is_admin);
```

To hand authorization entirely to Filament's own panel / resource policies instead,
set the ability to `null` (or `''`) — the package then defers rather than gating:

```php
'authorization' => ['ability' => null],
```

The gate is applied in exactly one place per surface kind — resources through
`SwarmResource`, pages through `SwarmPage`, widgets through `SwarmWidget` — so
authorization cannot drift surface-to-surface.

## Surfaces

All read-only, all grouped under the configured navigation group (default `Swarm`):

- **Runs explorer** — a filterable/sortable index of swarm runs with a per-run
  detail view (status, topology, context, output, and the step timeline).
- **Durable run inspector** — the full durable execution record: lifecycle
  markers, parallel branches, child runs, hierarchical node outputs, waits,
  signals, progress, and history.
- **Memory viewer** — agent memory snapshots (policy-filtered at freeze time),
  with redacted values shown as a clear marker.
- **Streaming / causal-log viewer** — a per-run, per-node timeline of the
  append-only causal log, with void-edge markers.
- **Health dashboard** — a pass/fail readiness view of the durable and audit
  persistence lanes (a page plus a companion widget).
- **Audit surfaces** — a non-consuming outbox health dashboard (coexists with
  the `swarm:relay` drainer), a single-row payload detail, and the per-run audit
  trace.

Sealed payloads are display-decrypted per field by laravel-swarm core; an
undecryptable value renders as `unavailable`, never as `sw0:` ciphertext.

## License

MIT © [Daniel Berry / Built by Berry](https://builtbyberry.com)
