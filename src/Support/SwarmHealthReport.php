<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarmFilament\Support;

use BuiltByBerry\LaravelSwarm\Contracts\InspectsDurableRuns;
use BuiltByBerry\LaravelSwarm\Contracts\ReadableAuditOutbox;
use Throwable;

/**
 * The pure health evaluator behind the read-only health dashboard — the
 * `swarm:health` readiness signal reduced to a display-safe pass/fail/degraded
 * verdict per persistence lane.
 *
 * Consumes ONLY the public read seams: the durable lane is probed through
 * {@see InspectsDurableRuns} and the audit lane through
 * {@see ReadableAuditOutbox::healthSummary()} / {@see ReadableAuditOutbox::isAvailable()}.
 * It never touches the `@internal` stores or cipher, never reads a run payload,
 * and never runs a control verb.
 *
 * Three states are distinguished per check:
 *
 * - **pass** — the lane's store is reachable and reports no problem;
 * - **fail** — the lane is reachable but reports a real problem an operator must
 *   act on (e.g. undelivered audit evidence sitting in the dead-letter queue);
 * - **degraded** — the check could not run: the store is unavailable/not
 *   configured, or a probe threw. A degraded check NEVER 500s the surface — the
 *   throwable is swallowed and mapped to a fixed, safe summary.
 *
 * Overall {@see $ok} mirrors `swarm:health`'s exit semantics: it is false only on a
 * hard **fail** — a degraded lane (e.g. durable persistence simply not configured)
 * surfaces its own badge but does not flip the headline, so an app that does not
 * use durable execution is not perpetually "unhealthy". {@see $status} carries the
 * nuance (the worst tier present) for the banner.
 *
 * Pure and container-free: it takes the two contracts and returns a value object,
 * so it is unit-testable directly with scripted/throwing stubs and the page/widget
 * just render the result.
 */
final class SwarmHealthReport
{
    public const PASS = 'pass';

    public const FAIL = 'fail';

    public const DEGRADED = 'degraded';

    /**
     * A run id that is never issued by core — the durable reachability probe looks
     * it up and expects a clean "not found" (null), which proves the store answered
     * without reading any real run's payload.
     */
    private const DURABLE_PROBE_RUN_ID = '__swarm_filament_health_probe__';

    /**
     * @param  list<HealthCheck>  $checks
     */
    private function __construct(
        public bool $ok,
        public string $status,
        public array $checks,
    ) {}

    public static function for(InspectsDurableRuns $durable, ReadableAuditOutbox $audit): self
    {
        $checks = [
            self::durableCheck($durable),
            self::auditCheck($audit),
        ];

        return new self(
            ok: ! self::hasStatus($checks, self::FAIL),
            status: self::overallStatus($checks),
            checks: $checks,
        );
    }

    /**
     * Probe durable persistence reachability without reading any run: a sentinel
     * lookup returns null when the store is reachable, and throws when it is not
     * (cache mode, missing tables, unreachable connection) — which degrades rather
     * than propagating.
     */
    private static function durableCheck(InspectsDurableRuns $durable): HealthCheck
    {
        try {
            $durable->find(self::DURABLE_PROBE_RUN_ID);

            return new HealthCheck(
                key: 'durable',
                label: 'Durable persistence',
                status: self::PASS,
                summary: 'Durable run store is reachable.',
            );
        } catch (Throwable) {
            return new HealthCheck(
                key: 'durable',
                label: 'Durable persistence',
                status: self::DEGRADED,
                summary: 'Durable run store is unavailable — durable persistence may not be configured.',
            );
        }
    }

    /**
     * Read the audit outbox's non-mutating health summary (counts only, no
     * decryption). Not backed by a persistent store → degraded; reachable with
     * dead-letter rows → fail (undelivered audit evidence needs reconciliation);
     * otherwise → pass.
     */
    private static function auditCheck(ReadableAuditOutbox $audit): HealthCheck
    {
        try {
            $summary = $audit->healthSummary();

            if (($summary['available'] ?? false) !== true) {
                return new HealthCheck(
                    key: 'audit',
                    label: 'Audit persistence',
                    status: self::DEGRADED,
                    summary: 'Audit outbox is not backed by a persistent store.',
                );
            }

            $deadLetter = (int) ($summary['dead_letter'] ?? 0);

            if ($deadLetter > 0) {
                return new HealthCheck(
                    key: 'audit',
                    label: 'Audit persistence',
                    status: self::FAIL,
                    summary: $deadLetter.' undelivered audit record(s) in the dead-letter queue require reconciliation.',
                );
            }

            $pending = (int) ($summary['pending'] ?? 0);

            return new HealthCheck(
                key: 'audit',
                label: 'Audit persistence',
                status: self::PASS,
                summary: 'Audit outbox is reachable ('.$pending.' pending).',
            );
        } catch (Throwable) {
            return new HealthCheck(
                key: 'audit',
                label: 'Audit persistence',
                status: self::DEGRADED,
                summary: 'Audit outbox did not respond.',
            );
        }
    }

    /**
     * The worst tier present: fail beats degraded beats pass.
     *
     * @param  list<HealthCheck>  $checks
     */
    private static function overallStatus(array $checks): string
    {
        if (self::hasStatus($checks, self::FAIL)) {
            return self::FAIL;
        }

        if (self::hasStatus($checks, self::DEGRADED)) {
            return self::DEGRADED;
        }

        return self::PASS;
    }

    /**
     * @param  list<HealthCheck>  $checks
     */
    private static function hasStatus(array $checks, string $status): bool
    {
        foreach ($checks as $check) {
            if ($check->status === $status) {
                return true;
            }
        }

        return false;
    }

    /**
     * A Filament color token for the overall status — drives the page/widget banner.
     */
    public function color(): string
    {
        return match ($this->status) {
            self::PASS => 'success',
            self::FAIL => 'danger',
            default => 'gray',
        };
    }
}
