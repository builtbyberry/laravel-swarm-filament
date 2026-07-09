<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarmFilament\Support;

use BuiltByBerry\LaravelSwarmFilament\SwarmFilamentServiceProvider;

/**
 * A request-scoped memo of run-id → summary line, so the runs index resolves each
 * visible row's display record at most once per render.
 *
 * This is bound as a container `scoped()` instance (see
 * {@see SwarmFilamentServiceProvider}), which
 * Laravel/Octane flushes on every `RequestReceived` via
 * `forgetScopedInstances()`. That per-request reset is the whole point: the memo
 * never serves a value produced in an earlier request and never grows unbounded
 * across a worker's life — the two defects a `static` cache carries under Octane.
 *
 * A null result IS a hit intra-request. `findForDisplay()` is deterministic within
 * a single request, so a run whose display record is absent/undecryptable resolves
 * to null once and is served from the memo for the rest of that render. The
 * per-request reset — not a per-value TTL — is what stops a stale null from
 * outliving the request that produced it.
 */
final class RunSummaryMemo
{
    /**
     * @var array<string, ?string>
     */
    private array $summaries = [];

    /**
     * Return the memoized summary for a run, resolving it via $resolve on the
     * first request-local miss. A null result is memoized as a hit (correct
     * within a request; cleared at the request boundary).
     *
     * @param  callable():?string  $resolve
     */
    public function remember(string $runId, callable $resolve): ?string
    {
        if (array_key_exists($runId, $this->summaries)) {
            return $this->summaries[$runId];
        }

        return $this->summaries[$runId] = $resolve();
    }

    /**
     * How many run summaries are currently memoized. Exposed so tests can assert
     * the memo stays bounded to a single render's rows rather than accumulating
     * across requests.
     */
    public function count(): int
    {
        return count($this->summaries);
    }
}
