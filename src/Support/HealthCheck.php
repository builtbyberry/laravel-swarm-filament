<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarmFilament\Support;

/**
 * One readiness check in a {@see SwarmHealthReport} — a single persistence lane
 * (durable or audit) reduced to a display-safe pass/fail/degraded verdict.
 *
 * Carries only a short, non-sensitive human summary: never a run payload, never a
 * raw exception message (which could leak schema/connection detail to a read-only
 * viewer), never `sw0:` ciphertext. The evaluator maps every outcome to one of the
 * three {@see SwarmHealthReport} status constants before constructing this.
 */
final class HealthCheck
{
    public function __construct(
        public string $key,
        public string $label,
        public string $status,
        public string $summary,
    ) {}

    public function passed(): bool
    {
        return $this->status === SwarmHealthReport::PASS;
    }

    public function failed(): bool
    {
        return $this->status === SwarmHealthReport::FAIL;
    }

    public function degraded(): bool
    {
        return $this->status === SwarmHealthReport::DEGRADED;
    }

    /**
     * A Filament color token for the check status — shared by the page and the
     * widget so the two surfaces never diverge on how a status is coloured.
     */
    public function color(): string
    {
        return match ($this->status) {
            SwarmHealthReport::PASS => 'success',
            SwarmHealthReport::FAIL => 'danger',
            default => 'gray',
        };
    }
}
