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
- **Runs explorer.** A filter/sort/paginate index of swarm runs (over
  plaintext-only columns) with a per-run detail view — status, topology, context,
  output, and the step timeline.
- **Durable run inspector.** The full durable execution record via
  `InspectsDurableRuns::inspect()`: lifecycle markers, parallel branches, child
  runs, hierarchical node outputs, waits, signals, progress, and run history.
- **Memory viewer.** Agent memory snapshots via `SnapshotsMemory` (the
  policy-filtered frozen view), with redacted values rendered as a clear marker.
- **Streaming / causal-log viewer.** A per-run, per-node timeline of the
  append-only causal log via `StreamEventStore::events()`, including void-edge
  markers (with a distinct marker for a missing/out-of-window predecessor).
- **Health dashboard.** A pass/fail/degraded readiness view of the durable and
  audit persistence lanes (a Filament page plus a companion widget), sourced from
  the public read seams — no payloads, no control actions.
- **Audit surfaces.** A non-consuming outbox health dashboard (pure SELECT reads
  that coexist with the `swarm:relay` drainer), a payload-minimized single-row
  detail that decrypts on demand, and a per-run audit-trace timeline (with a
  clean empty-state when no readable audit sink is bound).
