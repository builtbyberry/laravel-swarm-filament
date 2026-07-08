<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarmFilament\Widgets;

use BuiltByBerry\LaravelSwarmFilament\Concerns\AuthorizesSwarmObservability;
use BuiltByBerry\LaravelSwarmFilament\Resources\SwarmResource;
use BuiltByBerry\LaravelSwarmFilament\Support\SwarmObservabilityGate;
use Filament\Widgets\Widget;

/**
 * Base class for the read-only observability **Widgets** (health summary, …).
 *
 * The parallel of {@see SwarmResource}
 * for widgets. A Filament {@see Widget} authorizes through `canView(): bool` — a
 * different signature from both a Resource (`canViewAny()` / `canView(Model)`) and
 * a Page (`canAccess()`), so widgets cannot share the Resource's
 * {@see AuthorizesSwarmObservability}
 * trait either. The deny-by-default {@see SwarmObservabilityGate} decision is
 * applied here once so every Swarm widget inherits it and the widget authorization
 * surface can never drift widget-to-widget (change-review finding #3-F5).
 */
abstract class SwarmWidget extends Widget
{
    public static function canView(): bool
    {
        return SwarmObservabilityGate::allows();
    }
}
