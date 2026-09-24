# Laravel Swarm — Filament

Free, read-only [Filament](https://filamentphp.com) observability panel for [Laravel Swarm](https://github.com/builtbyberry/laravel-swarm) — inspect swarm runs, steps, durable state, memory, streaming, and audit-outbox health directly from your app's Filament panel.

> **View-only by design.** This package surfaces run data through Swarm's public read-only contracts (`InspectsDurableRuns`, `ReadableRunHistoryStore`, `ReadableAuditOutbox`, added in laravel-swarm v0.19). Every sealed field is display-decrypted per row — honoring `swarm.persistence.decrypt_failure_policy`, degrading an undecryptable value to a marked "unavailable" rather than throwing or leaking ciphertext — and no read ever mutates state. Operator control (pause/resume/cancel) lives in the separate paid operator console.

## Requirements

- PHP 8.4+
- Filament 5.x
- `builtbyberry/laravel-swarm` ^0.19 through ^0.27

The v0.3.0 `^0.27` compatibility work was tested against pinned core candidate
`48ad4ef690363ca40ba7d3bd50e63e7fbe76ba4b`, with official Laravel AI v1.0.0
(source `101c7ea33cd8569d82570f753fbf38e48b7d3d95`) and current stable `^1.0`.
CI retains lowest dependencies, published core v0.25.0, and the prior core 0.26
candidate `e25842cab4291837dcce2ff6f4815e58feab9079` with minimum/current official
AI `^0.11.2`. All six profiles run on PHP 8.4 and 8.5, with Filament 5 and its
resolved Livewire 4 requirements. Candidate metadata exists only in temporary
compatibility manifests. Those lanes are historical prepublication evidence;
fresh Packagist-only ecosystem installation remains a separate shipping gate.

### Token accounting

The Runs Tokens column, detail headline, recent Tokens stat and workflow graph
read both legacy `prompt_tokens` / `completion_tokens` and native
`input_tokens` / `output_tokens`. A total requires both primary values to be
nonnegative integers; known zero displays as `0`. Cache and reasoning subsets
are not added again. Empty, missing, null or malformed accounting displays as
`Unavailable`, never a fabricated zero or partial total.

`Mixed or unavailable` means the stored aggregate cannot supply a complete
single-generation total. This includes the all-null sentinel with both primary
name pairs; that sentinel alone does not prove both generations occurred.
The recent stat also uses this label when its window includes both legacy and
native reports, and withholds totals if any contributing run is unknown. An
empty history shows `0` over zero runs. Structural graph nodes and workers that
have not executed have no invocation count. Historical rows are not rewritten;
original per-step evidence remains intact. See [UPGRADING.md](UPGRADING.md).

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

To turn the package gate off entirely, set the ability to `null` (or `''`). Every
surface then becomes visible to **any user who can reach the Filament panel** — these
hooks _are_ the resource's authorization, so this grants access rather than deferring
to a per-resource policy. Use it only when the panel itself is already suitably locked
down:

```php
'authorization' => ['ability' => null],
```

The gate is applied in exactly one place per surface kind — resources through
`SwarmResource`, pages through `SwarmPage`, widgets through `SwarmWidget` — so
authorization cannot drift surface-to-surface.

## Surfaces

The information architecture is run-centric: the run is the hero object, so the
navigation has just two destinations, both read-only and grouped under the
configured navigation group (default `Swarm`):

- **Runs** — a filterable/sortable index of swarm runs with a per-run detail
  view. The detail view tells the whole story of one run: status, topology,
  context, output, and the step timeline, with durable execution, memory,
  streaming, and audit folded in as facets of that run rather than separate
  destinations:
  - **Durable execution** — lifecycle markers, parallel branches, child runs,
    hierarchical node outputs, waits, signals, progress, and run history.
  - **Memory** — agent memory snapshots (policy-filtered at freeze time), with
    redacted values shown as a clear marker.
  - **Streaming** — the per-node timeline of the append-only causal log, with
    void-edge markers.
  - **Audit** — the per-run audit trace, plus payload detail decrypted on demand
    (with a clean empty-state when no readable audit sink is bound).
- **Health** — a pass/fail/degraded readiness view of the durable and audit
  persistence lanes (a page plus a companion widget), including a non-consuming
  outbox health check that coexists with the `swarm:relay` drainer.

Sealed payloads are display-decrypted per field by laravel-swarm core; an
undecryptable value renders as `unavailable`, never as `sw0:` ciphertext.

## License

MIT © [Daniel Berry / Built by Berry](https://builtbyberry.com)
