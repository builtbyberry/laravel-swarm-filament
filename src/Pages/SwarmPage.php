<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarmFilament\Pages;

use BuiltByBerry\LaravelSwarmFilament\Concerns\AuthorizesSwarmObservability;
use BuiltByBerry\LaravelSwarmFilament\Concerns\ResolvesSwarmNavigation;
use BuiltByBerry\LaravelSwarmFilament\Resources\SwarmResource;
use BuiltByBerry\LaravelSwarmFilament\Support\SwarmObservabilityGate;
use Filament\Pages\Concerns\CanAuthorizeAccess;
use Filament\Pages\Page;

/**
 * Base class for the read-only observability **Pages** — the global Health
 * dashboard ({@see SwarmHealthPage}).
 *
 * Under the run-centric IA, streaming and audit are facets of a run rather than
 * standalone pages, so Health is the only page today; this stays an abstract base
 * so the shared authorization seam holds if further pages are ever added.
 *
 * The parallel of {@see SwarmResource}
 * for pages. A Filament {@see Page} authorizes through
 * `canAccess(): bool` (the {@see CanAuthorizeAccess}
 * signature — no `$parameters`, unlike a *resource* page), so it cannot share the
 * Resource's {@see AuthorizesSwarmObservability}
 * trait. The deny-by-default {@see SwarmObservabilityGate} decision is applied here
 * once so every Swarm page inherits it and the page authorization surface can never
 * drift page-to-page (change-review finding #3-F5). Navigation placement comes from
 * the shared {@see ResolvesSwarmNavigation} concern, the same home the resources use.
 */
abstract class SwarmPage extends Page
{
    use ResolvesSwarmNavigation;

    public static function canAccess(): bool
    {
        return SwarmObservabilityGate::allows();
    }
}
