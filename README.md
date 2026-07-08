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

Gate access to the observability surfaces in your host app (a Filament access policy or `canAccessPanel`) — the package is authorization-agnostic, matching Swarm's core read contracts.

## License

MIT © [Daniel Berry / Built by Berry](https://builtbyberry.com)
