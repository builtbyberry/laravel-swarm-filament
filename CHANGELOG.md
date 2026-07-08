# Changelog

## v0.1.0 - unreleased

First release — free, read-only Filament observability for Laravel Swarm.

### Added

- **Package foundation.** Auto-discovered `SwarmFilamentServiceProvider` and a `SwarmFilamentPlugin` you add to a Filament panel (`->plugin(SwarmFilamentPlugin::make())`). Targets Filament v5 and `builtbyberry/laravel-swarm ^0.19`. Read-only observability surfaces read persisted swarm data exclusively through the public `InspectsDurableRuns`, `ReadableRunHistoryStore`, and `ReadableAuditOutbox` contracts — display-decrypted per row, never mutating, never touching the `@internal` cipher.
