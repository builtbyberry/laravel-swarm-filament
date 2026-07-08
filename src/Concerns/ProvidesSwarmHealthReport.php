<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarmFilament\Concerns;

use BuiltByBerry\LaravelSwarm\Contracts\InspectsDurableRuns;
use BuiltByBerry\LaravelSwarm\Contracts\ReadableAuditOutbox;
use BuiltByBerry\LaravelSwarmFilament\Pages\SwarmHealthPage;
use BuiltByBerry\LaravelSwarmFilament\Support\SwarmHealthReport;
use BuiltByBerry\LaravelSwarmFilament\Widgets\SwarmHealthWidget;
use Psr\Log\LoggerInterface;

/**
 * Shared health-report sourcing for the read-only health surfaces.
 *
 * The health {@see SwarmHealthPage} and
 * {@see SwarmHealthWidget} extend
 * different Filament bases and so cannot share a common parent — mirroring the
 * repo's can't-share-a-base → share-a-trait precedent
 * ({@see AuthorizesSwarmObservability}), the report is resolved once here from the
 * public read seams and both surfaces render the identical verdict. A logger is
 * passed so a failing probe is logged, not silently degraded.
 */
trait ProvidesSwarmHealthReport
{
    public function report(): SwarmHealthReport
    {
        return SwarmHealthReport::for(
            app(InspectsDurableRuns::class),
            app(ReadableAuditOutbox::class),
            app(LoggerInterface::class),
        );
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        return [
            'report' => $this->report(),
        ];
    }
}
