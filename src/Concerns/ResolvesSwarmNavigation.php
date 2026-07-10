<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarmFilament\Concerns;

use BuiltByBerry\LaravelSwarmFilament\Pages\SwarmPage;
use BuiltByBerry\LaravelSwarmFilament\Resources\SwarmResource;

/**
 * The single, config-driven navigation placement for every read-only
 * observability surface — resources and pages alike.
 *
 * Both {@see SwarmResource} and
 * {@see SwarmPage} apply this trait, so
 * the `swarm-filament.navigation.*` config is read in one place and the group /
 * sort can never drift surface-to-surface.
 */
trait ResolvesSwarmNavigation
{
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
