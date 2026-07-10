<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarmFilament\Support;

use BuiltByBerry\LaravelSwarm\Contracts\InspectsDurableRuns;
use BuiltByBerry\LaravelSwarm\Contracts\ReadableAuditOutbox;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The pure health evaluator behind the read-only health dashboard — the
 * `swarm:health` readiness signal reduced to a display-safe {@see HealthStatus}
 * verdict per persistence lane.
 *
 * Consumes ONLY the public read seams: the durable lane is probed through
 * {@see InspectsDurableRuns} and the audit lane through
 * {@see ReadableAuditOutbox::healthSummary()} / {@see ReadableAuditOutbox::isAvailable()}.
 * It never touches the `@internal` stores or cipher, never reads a run payload,
 * and never runs a control verb.
 *
 * Overall {@see $ok} mirrors `swarm:health`'s exit semantics: it is false only on a
 * hard **fail** — a degraded lane (e.g. durable persistence simply not configured)
 * surfaces its own badge but does not flip the headline, so an app that does not
 * use durable execution is not perpetually "unhealthy". {@see $status} carries the
 * nuance (the worst tier present) for the banner.
 *
 * A probe that throws degrades the lane rather than 500ing the surface — but the
 * throwable is NOT silently swallowed: when a {@see LoggerInterface} is supplied it
 * is logged at warning first, so a genuine read-path bug is distinguishable from an
 * unconfigured lane. Pure and container-free otherwise: with no logger it takes the
 * two contracts and returns a value object, so it is unit-testable directly with
 * scripted/throwing stubs and the page/widget just render the result.
 */
final class SwarmHealthReport
{
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
        public readonly bool $ok,
        public readonly HealthStatus $status,
        public readonly array $checks,
    ) {}

    public static function for(InspectsDurableRuns $durable, ReadableAuditOutbox $audit, ?LoggerInterface $logger = null): self
    {
        $checks = [
            self::durableCheck($durable, $logger),
            self::auditCheck($audit, $logger),
        ];

        return new self(
            ok: ! self::hasStatus($checks, HealthStatus::Fail),
            status: self::overallStatus($checks),
            checks: $checks,
        );
    }

    /**
     * Probe durable persistence reachability without reading any run.
     *
     * The probe looks up a sentinel run id. `InspectsDurableRuns::find()` documents
     * a clean null for an UNKNOWN key — but that guarantee is about a missing row,
     * not a broken connection: when the persistence connection is unreachable or the
     * durable tables are absent (cache-mode installs, un-migrated apps) the query
     * layer throws (a PDOException etc.) regardless of the unknown-key semantics.
     * So `find(sentinel)` returning at all — null included — is the reachability
     * signal (pass); a throw means the lane could not answer (degraded). No real run
     * is ever read either way.
     */
    private static function durableCheck(InspectsDurableRuns $durable, ?LoggerInterface $logger): HealthCheck
    {
        try {
            $durable->find(self::DURABLE_PROBE_RUN_ID);

            return new HealthCheck(
                key: 'durable',
                label: 'Durable persistence',
                status: HealthStatus::Pass,
                summary: 'Durable run store is reachable.',
            );
        } catch (Throwable $exception) {
            $logger?->warning('Swarm health: durable persistence probe failed.', ['exception' => $exception]);

            return new HealthCheck(
                key: 'durable',
                label: 'Durable persistence',
                status: HealthStatus::Degraded,
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
    private static function auditCheck(ReadableAuditOutbox $audit, ?LoggerInterface $logger): HealthCheck
    {
        try {
            $summary = $audit->healthSummary();

            if (($summary['available'] ?? false) !== true) {
                return new HealthCheck(
                    key: 'audit',
                    label: 'Audit persistence',
                    status: HealthStatus::Degraded,
                    summary: 'Audit outbox is not backed by a persistent store.',
                );
            }

            $deadLetter = (int) ($summary['dead_letter'] ?? 0);

            if ($deadLetter > 0) {
                return new HealthCheck(
                    key: 'audit',
                    label: 'Audit persistence',
                    status: HealthStatus::Fail,
                    summary: $deadLetter.' undelivered audit record(s) in the dead-letter queue require reconciliation.',
                );
            }

            $pending = (int) ($summary['pending'] ?? 0);

            return new HealthCheck(
                key: 'audit',
                label: 'Audit persistence',
                status: HealthStatus::Pass,
                summary: 'Audit outbox is reachable ('.$pending.' pending).',
            );
        } catch (Throwable $exception) {
            $logger?->warning('Swarm health: audit outbox probe failed.', ['exception' => $exception]);

            return new HealthCheck(
                key: 'audit',
                label: 'Audit persistence',
                status: HealthStatus::Degraded,
                summary: 'Audit outbox did not respond.',
            );
        }
    }

    /**
     * The worst tier present: fail beats degraded beats pass.
     *
     * @param  list<HealthCheck>  $checks
     */
    private static function overallStatus(array $checks): HealthStatus
    {
        if (self::hasStatus($checks, HealthStatus::Fail)) {
            return HealthStatus::Fail;
        }

        if (self::hasStatus($checks, HealthStatus::Degraded)) {
            return HealthStatus::Degraded;
        }

        return HealthStatus::Pass;
    }

    /**
     * @param  list<HealthCheck>  $checks
     */
    private static function hasStatus(array $checks, HealthStatus $status): bool
    {
        foreach ($checks as $check) {
            if ($check->status === $status) {
                return true;
            }
        }

        return false;
    }
}
