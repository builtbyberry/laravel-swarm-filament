# Changelog

## v0.2.0 - 2026-07-13

Compatibility release. Widens the `builtbyberry/laravel-swarm` requirement to
`^0.19 || ^0.20` so the panel installs alongside Laravel Swarm v0.20 (which
tracks `laravel/ai` ^0.9). No swarm read-surface changed — the panel continues
to read exclusively through the public v0.19 inspection contracts
(`InspectsDurableRuns`, `ReadableRunHistoryStore`, `SnapshotsMemory`,
`StreamEventStore`, `ReadableAuditOutbox`), which are unchanged in v0.20. The
full suite, PHPStan (level 8), and Pint all pass against Swarm v0.20.

### Changed

- **Support Laravel Swarm ^0.20.** `composer.json` now allows
  `builtbyberry/laravel-swarm: ^0.19 || ^0.20`. No behavioral change; view-only,
  deny-by-default authorization and per-row display-decryption are unchanged.

## v0.1.0 - 2026-07-09

First release — a free, read-only [Filament](https://filamentphp.com) observability
panel for [Laravel Swarm](https://github.com/builtbyberry/laravel-swarm). The panel
is **run-centric**: a run is the hero object, and its flow, durable state, memory,
streaming, artifacts, and audit trail are shown as facets *of that run* rather than
as separate top-level tables. Navigation is just **Runs** and **Health**.

Every surface reads persisted swarm data exclusively through laravel-swarm's public
read contracts (`InspectsDurableRuns`, `ReadableRunHistoryStore`, `SnapshotsMemory`,
`StreamEventStore`, `ReadableAuditOutbox`, and the app's optional
`ReadableSwarmAuditSink`) — never the `@internal` cipher and never a mutating call.
Sealed fields are display-decrypted per row through a single chokepoint that masks
`sw0:` ciphertext at any nesting depth; an undecryptable value degrades to
`unavailable` rather than throwing or leaking. Operator control
(pause/resume/cancel/send-signal) is deliberately out of scope — it lives in the
separate paid operator console.

### Added

- **Package foundation.** Auto-discovered `SwarmFilamentServiceProvider` and a
  `SwarmFilamentPlugin` you add to a Filament panel
  (`->plugin(SwarmFilamentPlugin::make())`). Targets Filament v5, PHP 8.5+, and
  `builtbyberry/laravel-swarm ^0.19` (its public read-only inspection contracts).

- **Deny-by-default authorization.** Every surface authorizes against a configurable
  Gate ability (`config('swarm-filament.authorization.ability')`, default
  `viewSwarmObservability`) before rendering — absent a Gate definition, access is
  denied. Set the ability to `null` to make every surface visible to any panel user
  (a grant, not a hand-off to a per-resource policy). Applied once per surface kind
  via `SwarmResource`, `SwarmPage`, and `SwarmWidget` so it cannot drift.

- **Runs explorer.** A filter/sort/paginate index of swarm runs over plaintext-only
  columns, led by an aggregate stats strip (total runs, needs-attention count,
  recent p50 latency, recent token throughput). Each row scans as a plain-language
  line — what the run was asked to do — with status, duration, and tokens.

- **Run detail — one story, top to bottom.** A plain-language headline (the run's
  "so what": outcome, shape, cost/latency), the request that started it, and the
  **flow** as the spine: a server-computed SVG workflow graph (no JS graph
  dependency, CSP-safe, theme-aware) that renders the true DAG — the authored route
  plan, the durable branch/child execution tree, or the topology-derived flow,
  richest first. Click any node for everything about that step: input → output,
  tokens, duration, attempts, the memory it wrote and could see, and the tools it
  called. The final output renders as Markdown.

- **Run facets — folded into the run, not separate destinations:**
  - **Failure** — a failed run shows *why*: the captured exception class and
    message, plus per-node failures, display-decrypted and degrade-safe.
  - **Artifacts** — a read-only list of the artifacts the run captured, each with
    its producing agent; content is routed through the sealed-value chokepoint.
  - **Durable execution** — for durable runs, a read-only view of what the run is
    blocked on (waits), signals received, progress markers, and
    retry/recovery/timeout status. No control actions.
  - **Streaming** — a per-node timeline of the append-only causal log via
    `StreamEventStore::events()`, including void-edge markers (with a distinct
    marker for a missing/out-of-window predecessor).
  - **Audit trail** — a compact one-line-per-event timeline of the evidence the run
    emitted, via a non-consuming read that coexists with the `swarm:relay` drainer;
    a clean empty-state when no readable audit sink is bound.
  - **Memory** — the agent memory a step wrote and could see, surfaced inline on the
    flow (via `SnapshotsMemory`, the policy-filtered frozen view; redacted values
    shown as a clear marker) rather than as a separate snapshots list.

- **Health.** A pass/fail/degraded readiness view of the durable and audit
  persistence lanes — a Filament page plus a companion dashboard widget — sourced
  from the public read seams. No payloads, no control actions.

- **AGENTS.md.** Ecosystem invariants for contributors and agents: read-only through
  public contracts, Runs + Health as the only destinations, the single-chokepoint
  sealed-value rule, and deny-by-default authorization.

### Notes

- **Read-only and Octane-safe.** No surface mutates state; an accidental model write
  fails loud. No process-global mutable state — the runs-index summary memo is
  request-scoped.
- **Requirements.** PHP 8.5+, `builtbyberry/laravel-swarm ^0.19`, Filament v5.
