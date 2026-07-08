# Changelog

## v0.1.0 - unreleased

First release — free, read-only Filament observability for Laravel Swarm.

### Added

- **Package foundation.** Auto-discovered `SwarmFilamentServiceProvider` and a `SwarmFilamentPlugin` you add to a Filament panel (`->plugin(SwarmFilamentPlugin::make())`). Targets Filament v5 and `builtbyberry/laravel-swarm ^0.19`. Read-only observability surfaces read persisted swarm data exclusively through the public `InspectsDurableRuns`, `ReadableRunHistoryStore`, and `ReadableAuditOutbox` contracts — display-decrypted per row, never mutating, never touching the `@internal` cipher.
- **Health dashboard.** A read-only `SwarmHealthPage` and companion `SwarmHealthWidget` surface the `swarm:health` readiness signal — durable and audit persistence reachability — as a pass/fail/degraded view. The verdict is computed by a pure, unit-tested `SwarmHealthReport` evaluator that consumes only the public read seams (`InspectsDurableRuns` for durable reachability, `ReadableAuditOutbox::healthSummary()` for the audit lane); it surfaces no run payloads and no operator actions, and a lane whose check cannot run degrades to a marked state rather than 500ing. New `SwarmPage` / `SwarmWidget` base classes apply the deny-by-default `SwarmObservabilityGate` in the page's `canAccess()` and the widget's `canView()` — the non-Resource authorization signatures — so page/widget access can never drift from the resource gate.
