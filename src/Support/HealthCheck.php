<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarmFilament\Support;

/**
 * One readiness check in a {@see SwarmHealthReport} — a single persistence lane
 * (durable or audit) reduced to a display-safe {@see HealthStatus} verdict.
 *
 * Carries only a short, non-sensitive human summary: never a run payload, never a
 * raw exception message (which could leak schema/connection detail to a read-only
 * viewer), never `sw0:` ciphertext. Colour comes from the {@see HealthStatus} enum
 * ({@see HealthStatus::getColor()}), so it is defined in exactly one place.
 */
final readonly class HealthCheck
{
    public function __construct(
        public string $key,
        public string $label,
        public HealthStatus $status,
        public string $summary,
    ) {}

    public function passed(): bool
    {
        return $this->status === HealthStatus::Pass;
    }

    public function failed(): bool
    {
        return $this->status === HealthStatus::Fail;
    }

    public function degraded(): bool
    {
        return $this->status === HealthStatus::Degraded;
    }
}
