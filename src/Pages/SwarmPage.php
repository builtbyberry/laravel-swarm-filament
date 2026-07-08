<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarmFilament\Pages;

use BuiltByBerry\LaravelSwarmFilament\Concerns\AuthorizesSwarmObservability;
use BuiltByBerry\LaravelSwarmFilament\Resources\SwarmResource;
use BuiltByBerry\LaravelSwarmFilament\Support\SwarmObservabilityGate;
use Filament\Pages\Concerns\CanAuthorizeAccess;
use Filament\Pages\Page;

/**
 * Base class for the read-only observability **Pages** (health dashboard, …).
 *
 * The parallel of {@see SwarmResource}
 * for standalone pages. A Filament {@see Page} authorizes through
 * `canAccess(): bool` (the {@see CanAuthorizeAccess}
 * signature — no `$parameters`, unlike a *resource* page), so it cannot share the
 * Resource's {@see AuthorizesSwarmObservability}
 * trait. The deny-by-default {@see SwarmObservabilityGate} decision is applied here
 * once so every Swarm page inherits it and the page authorization surface can never
 * drift page-to-page (change-review finding #3-F5).
 */
abstract class SwarmPage extends Page
{
    public static function canAccess(): bool
    {
        return SwarmObservabilityGate::allows();
    }

    public static function getNavigationGroup(): ?string
    {
        $group = config('swarm-filament.navigation.group');

        return is_string($group) ? $group : null;
    }

    public static function getNavigationSort(): ?int
    {
        $sort = config('swarm-filament.navigation.sort');

        return is_int($sort) ? $sort : null;
    }
}
