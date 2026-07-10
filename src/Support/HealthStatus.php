<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarmFilament\Support;

use Filament\Support\Contracts\HasColor;
use Illuminate\Support\Str;

/**
 * The verdict for one persistence lane in a {@see SwarmHealthReport}, and for the
 * report overall.
 *
 * - **Pass** — the lane's store is reachable and reports no problem;
 * - **Fail** — the lane is reachable but reports a real problem an operator must
 *   act on (e.g. undelivered audit evidence in the dead-letter queue);
 * - **Degraded** — the check could not run: the store is unavailable/not
 *   configured, or a probe threw.
 *
 * Implements Filament's {@see HasColor} so the status→color mapping lives in ONE
 * place — the page and widget colour a badge straight from the enum and can never
 * diverge. Being a backed enum also removes the stringly-typed `default` arm that
 * silently swallowed invalid status values.
 */
enum HealthStatus: string implements HasColor
{
    case Pass = 'pass';

    case Fail = 'fail';

    case Degraded = 'degraded';

    public function getColor(): string
    {
        return match ($this) {
            self::Pass => 'success',
            self::Fail => 'danger',
            self::Degraded => 'gray',
        };
    }

    /**
     * A human display label for a badge — the single display transform, so the
     * page and widget never format the status differently.
     */
    public function label(): string
    {
        return Str::ucfirst($this->value);
    }
}
