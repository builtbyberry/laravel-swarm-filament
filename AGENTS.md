# Laravel Swarm — Filament: Agent Context

Operational context for AI coding agents. Human contributors should start with
`README.md`, `CHANGELOG.md`, and `docs/public-surface.md`.

This package is the **free, read-only Filament observability panel** for
[Laravel Swarm](https://github.com/builtbyberry/laravel-swarm). It surfaces runs,
durable state, memory, streaming, and audit-outbox health — and nothing else.
Operator control (pause/resume/cancel/signal) lives in the separate paid operator
console; it is a non-goal here. Keep every contribution view-only and Laravel-native.

The invariants below are load-bearing. They currently live scattered across PHPDoc;
this file collects them so a change never quietly breaks one. Verify against the code
before trusting any restatement.

## Read only, and only through public contracts

Companions source persisted swarm data **exclusively** through laravel-swarm's
public read contracts — never the `@internal` `SwarmPersistenceCipher`, never a
re-implemented cast, and never a mutating call:

- `ReadableRunHistoryStore::findForDisplay()` — the run/step display row (already
  display-decrypted per field, honoring `swarm.persistence.decrypt_failure_policy`).
- `InspectsDurableRuns::inspect()` — the durable execution record.
- `SnapshotsMemory` — the policy-filtered frozen memory snapshot.
- `StreamEventStore::events()` — the append-only causal log.
- `ReadableAuditOutbox` — non-consuming outbox health (pure SELECTs that coexist
  with the `swarm:relay` drainer; never `drain()`).
- `ReadableSwarmAuditSink` — the optional per-run audit trace, when the host binds a
  sink that implements it.

The only mentions of the cipher in `src/` are PHPDoc stating it is never touched.
Keep it that way: if you need a new read, add or use a public contract seam.

## Navigation is only Runs + Health

The information architecture is run-centric — the run is the hero object. The panel
registers exactly two destinations (`SwarmFilamentPlugin::register()`):

- **Runs** — the `SwarmRunResource` index plus the per-run detail view. Durable
  execution, memory, streaming, and audit are **facets of a run**, folded into that
  detail view — never standalone pages or resources.
- **Health** — the global persistence-lane readiness page (`SwarmHealthPage`) and its
  companion `SwarmHealthWidget`.

Do not add a new top-level page or resource for a data lane. If it belongs to a run,
it is a facet of the run detail view. The `SwarmResource` / `SwarmPage` /
`SwarmWidget` base classes stay abstract so the shared seam holds if a genuinely new
surface is ever justified.

## Authorize once per surface kind

Access is **deny-by-default**. Every surface authorizes against the configured Gate
ability (`config('swarm-filament.authorization.ability')`, default
`viewSwarmObservability`) through the single `SwarmObservabilityGate::allows()`
decision — applied exactly once per surface kind so it cannot drift:

- **Resources** — via the `AuthorizesSwarmObservability` trait on `SwarmResource`.
- **Pages** — `SwarmPage::canAccess()` (a Page's signature differs from a Resource's,
  so it can't share the trait).
- **Widgets** — `SwarmWidget::canView()` (different signature again).

A `null`/empty ability turns the package gate **off**: `allows()` returns `true` and
every surface becomes visible to any user who can reach the panel. These hooks *are*
the resource's authorization, so that is a **grant**, not a hand-off to a per-resource
policy. Use it only when the panel itself is already locked down. Any new surface
must route its authorization through `SwarmObservabilityGate` — never inline its own
Gate check.

## The sealed-value rule lives in DisplayField

`Support/DisplayField` is the **single chokepoint** for rendering a display-decrypted
field. It applies one invariant everywhere: a value that still looks like `sw0:`
ciphertext is treated as unavailable and rendered as a marked placeholder, never as
raw ciphertext — as defense in depth, even if the upstream row flagged it available.
This holds at **any nesting depth**: each string leaf of a structured value is masked
in the decoded structure *before* it is json-encoded, so a sealed leaf never renders
while its plaintext siblings survive (a partial mask).

Any new render site for potentially-sealed data routes through `DisplayField` (or
`RunDisplayPresenter::sealed()`, which wraps it). The peer presenters
(`StreamTimelinePresenter::scrub()`, `MemorySnapshotPresenter`) mirror the same
leaf-mask by design — keep them shaped alike. Never render decrypted data by hand.

## Command trio

Run these from the repo root (Herd PHP 8.5 — `source ~/.zshrc` first if needed):

- `composer test` — the Pest feature suite (`tests/Feature`).
- `composer analyse` — PHPStan.
- `composer lint` — Pint in `--test` mode (check only). Use `composer format` to
  apply fixes.

Run all three before proposing a PR that touches PHP. Match the existing Laravel /
Filament v5 conventions (Spatie `PackageServiceProvider`, `Plugin::get()` by id, the
v5 Schemas API) — this package's defining attribute is that it feels Filament-native.
