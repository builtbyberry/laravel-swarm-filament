# Changelog

## v0.1.0 - unreleased

First release — free, read-only Filament observability for Laravel Swarm. Every
surface reads persisted swarm data exclusively through laravel-swarm's public
read contracts (`InspectsDurableRuns`, `ReadableRunHistoryStore`,
`ReadableAuditOutbox`, and the app's optional `ReadableSwarmAuditSink`), never the
`@internal` cipher and never a mutating call. Sealed fields are display-decrypted
per row; an undecryptable value degrades to `unavailable` rather than throwing or
leaking `sw0:` ciphertext.

### Added

- **Package foundation.** Auto-discovered `SwarmFilamentServiceProvider` and a
  `SwarmFilamentPlugin` you add to a Filament panel
  (`->plugin(SwarmFilamentPlugin::make())`). Targets Filament v5 and
  `builtbyberry/laravel-swarm ^0.19`.
- **Deny-by-default authorization.** Every surface authorizes against a
  configurable Gate ability (`config('swarm-filament.authorization.ability')`,
  default `viewSwarmObservability`) before rendering — absent a Gate definition,
  access is denied. Set the ability to `null` to defer to Filament's own panel /
  resource authorization. Applied once per surface kind via `SwarmResource`,
  `SwarmPage`, and `SwarmWidget` so it cannot drift.
- **Runs — the hero surface.** Observability is organized around the *run*, not
  the storage tables behind it. The index is insight-first: each row leads with
  what the run was asked to do (its request), alongside status, wall-clock
  duration, and token cost. The per-run page reads as one story top to bottom — a
  plain-language outcome headline, a server-computed workflow graph that reflects
  the run's *actual* topology (a sequential chain, a parallel fan-out, a
  hierarchical coordinator routing to its workers, and a durable
  static-hierarchical run's authored route-plan DAG with per-node status), the
  final output rendered as Markdown, and — folded into the run as facets rather
  than separate destinations — the memory each step wrote and could see (via
  `SnapshotsMemory`), the per-node streaming causal log (via
  `StreamEventStore::events()`), and a compact audit-evidence timeline (via the
  app's optional `ReadableSwarmAuditSink`, with a clear empty-state when none is
  bound). Clicking a step reveals its full input/output, the memory it wrote and
  saw, and its tool calls.
- **Health dashboard.** A pass/fail/degraded readiness view of the durable and
  audit persistence lanes (a Filament page plus a companion widget), sourced from
  the public read seams — no payloads, no control actions.

The observability surface is intentionally run-centric: there are no standalone
memory / streaming / durable-inspector / audit-outbox destinations — each is a
facet of the run it belongs to. Navigation is just **Runs** and **Health**.
