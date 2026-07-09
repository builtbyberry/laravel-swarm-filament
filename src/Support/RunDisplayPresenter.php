<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarmFilament\Support;

use BuiltByBerry\LaravelSwarm\Contracts\ReadableRunHistoryStore;

/**
 * Maps a {@see ReadableRunHistoryStore::findForDisplay()}
 * row into a flat, render-ready array for the run/step infolist.
 *
 * Every sealed member (the run context, the run output, and each step's
 * input/output) is passed through {@see DisplayField} here — so the infolist
 * entries render already-safe strings and the leak-proofing lives in ONE tested
 * place rather than scattered across entry closures. Three display states are
 * distinguished for a sealed field:
 *
 * - **value** — decrypted and present;
 * - **`unavailable`** — present but undecryptable, or still `sw0:`-sealed
 *   (defense in depth) — never the raw ciphertext;
 * - **`none`** — simply absent/empty (a not-captured or not-yet-produced field),
 *   which is distinct from an undecryptable one.
 *
 * Pure and container-free: it takes the already-degraded display row and never
 * touches the cipher, the store, or Filament — so it is unit-testable directly.
 */
final class RunDisplayPresenter
{
    private const UNAVAILABLE = 'unavailable';

    private const NONE = 'none';

    /**
     * @param  array<string, mixed>  $display  a `findForDisplay()` row
     * @return array{run_id: ?string, swarm_class: ?string, topology: ?string, status: ?string, started_at: mixed, finished_at: mixed, context: string, output: string, steps: list<array{step_index: ?int, agent_class: ?string, role: ?string, decision: ?string, tokens: ?int, input: string, output: string}>, metrics: array{steps: int, tokens: ?int, duration: ?string}, error: array{message: ?string, class: ?string}|null, artifacts: list<array{name: string, content: string, step_agent_class: ?string}>, run_metadata: array{parent_run_id: ?string, execution_mode: ?string, tags: ?string}}
     */
    public static function present(array $display): array
    {
        $steps = self::steps($display['steps'] ?? null);

        return [
            'run_id' => self::scalar($display['run_id'] ?? null),
            'swarm_class' => self::scalar($display['swarm_class'] ?? null),
            'topology' => self::scalar($display['topology'] ?? null),
            'status' => self::scalar($display['status'] ?? null),
            'started_at' => $display['started_at'] ?? null,
            'finished_at' => $display['finished_at'] ?? null,
            'context' => self::context($display),
            'output' => self::sealed($display, 'output'),
            'steps' => $steps,
            // A run-level "so what" line: totals the flow can headline with.
            'metrics' => self::metrics($display, $steps),
            // The captured terminal failure — the "why" behind a red status.
            'error' => self::error($display),
            // Run-produced artifacts, content routed through the sealed chokepoint.
            'artifacts' => self::artifacts($display),
            // Plain-text operational metadata (lineage, mode, tags) — never sealed.
            'run_metadata' => self::runMetadata($display),
        ];
    }

    /**
     * The run-level failure detail, or null when the run did not capture one.
     *
     * The persisted `error` payload is `{message?: string, class: class-string}`;
     * `message` is omitted entirely under a Skip capture policy (class only). The
     * message is a captured string, so it is routed through {@see DisplayField} —
     * a sealed `sw0:` leaf is masked, never rendered raw (defense in depth, even
     * though the error column is not itself sealed). The class is a framework type
     * name (never decrypted data) and renders as a plain scalar.
     *
     * @param  array<string, mixed>  $display
     * @return array{message: ?string, class: ?string}|null
     */
    private static function error(array $display): ?array
    {
        $error = $display['error'] ?? null;

        if (! is_array($error) || $error === []) {
            return null;
        }

        $message = DisplayField::fromRow($error, 'message');

        return [
            'message' => $message->isAvailable() ? (string) $message->value : null,
            'class' => self::scalar($error['class'] ?? null),
        ];
    }

    /**
     * The run's captured artifacts, mapped for the read-only Artifacts facet. Each
     * artifact's `content` is a captured value routed through {@see DisplayField}
     * (partial leaf-mask at any depth), so a sealed `sw0:` value never renders raw
     * even though the artifacts column is not itself sealed. `name` and the
     * producing `step_agent_class` are framework-set identifiers, rendered scalar.
     *
     * @param  array<string, mixed>  $display
     * @return list<array{name: string, content: string, step_agent_class: ?string}>
     */
    private static function artifacts(array $display): array
    {
        $artifacts = $display['artifacts'] ?? null;

        if (! is_array($artifacts)) {
            return [];
        }

        $mapped = [];

        foreach ($artifacts as $artifact) {
            if (! is_array($artifact)) {
                continue;
            }

            $mapped[] = [
                'name' => self::scalar($artifact['name'] ?? null) ?? '(unnamed)',
                'content' => self::render(DisplayField::fromRow($artifact, 'content')),
                'step_agent_class' => self::scalar($artifact['step_agent_class'] ?? null),
            ];
        }

        return $mapped;
    }

    /**
     * The run's operational metadata surfaced in Run details: its lineage
     * (`parent_run_id`), the `execution_mode`, and any `tags`. These are
     * framework-set, plain-text index values — never sealed IO — so they render as
     * scalars (tags list flattened to a comma-joined string). Absent keys degrade
     * to null so the view can placeholder them.
     *
     * @param  array<string, mixed>  $display
     * @return array{parent_run_id: ?string, execution_mode: ?string, tags: ?string}
     */
    private static function runMetadata(array $display): array
    {
        $metadata = is_array($display['metadata'] ?? null) ? $display['metadata'] : [];

        $tags = $metadata['tags'] ?? null;
        if (is_array($tags)) {
            $tags = array_values(array_filter($tags, 'is_scalar'));
            $tags = $tags === [] ? null : implode(', ', array_map(static fn (mixed $t): string => (string) $t, $tags));
        } else {
            $tags = self::scalar($tags);
        }

        return [
            'parent_run_id' => self::scalar($metadata['parent_run_id'] ?? null),
            'execution_mode' => self::scalar($metadata['execution_mode'] ?? null),
            'tags' => $tags,
        ];
    }

    /**
     * Run headline metrics — the plain-language summary the flow leads with.
     * `tokens` and `duration` are null when there's nothing to report (e.g. a
     * scripted run with no LLM usage), so the flow shows them only when real.
     *
     * @param  array<string, mixed>  $display
     * @param  list<array<string, mixed>>  $steps
     * @return array{steps: int, tokens: ?int, duration: ?string}
     */
    private static function metrics(array $display, array $steps): array
    {
        $tokens = self::tokens(is_array($display['usage'] ?? null) ? $display['usage'] : []);

        return [
            'steps' => count($steps),
            'tokens' => $tokens > 0 ? $tokens : null,
            'duration' => self::duration($display['started_at'] ?? null, $display['finished_at'] ?? null),
        ];
    }

    /**
     * @param  array<string, mixed>  $usage
     */
    private static function tokens(array $usage): int
    {
        $prompt = is_numeric($usage['prompt_tokens'] ?? null) ? (int) $usage['prompt_tokens'] : 0;
        $completion = is_numeric($usage['completion_tokens'] ?? null) ? (int) $usage['completion_tokens'] : 0;

        return $prompt + $completion;
    }

    private static function duration(mixed $start, mixed $end): ?string
    {
        if (! is_string($start) || ! is_string($end)) {
            return null;
        }

        $from = strtotime($start);
        $to = strtotime($end);
        if ($from === false || $to === false || $to < $from) {
            return null;
        }

        $seconds = $to - $from;

        return $seconds > 0 ? $seconds.'s' : null;
    }

    /**
     * The run context arrives as a nested `['input' => <decrypted|null>]` array
     * paired with a top-level `context_available` flag (not a flat field like
     * `output`), so pull `context.input` and route it through {@see DisplayField}
     * for the same sw0:/degrade treatment as every other sealed member.
     *
     * @param  array<string, mixed>  $display
     */
    private static function context(array $display): string
    {
        $context = $display['context'] ?? null;

        return self::render(DisplayField::fromRow([
            'input' => is_array($context) ? ($context['input'] ?? null) : $context,
            'input_available' => (bool) ($display['context_available'] ?? true),
        ], 'input'));
    }

    /**
     * @return list<array{step_index: ?int, agent_class: ?string, role: ?string, decision: ?string, tokens: ?int, input: string, output: string}>
     */
    private static function steps(mixed $steps): array
    {
        if (! is_array($steps)) {
            return [];
        }

        $mapped = [];

        foreach ($steps as $step) {
            if (! is_array($step)) {
                continue;
            }

            $meta = is_array($step['metadata'] ?? null) ? $step['metadata'] : [];
            $stepTokens = self::tokens(is_array($meta['usage'] ?? null) ? $meta['usage'] : []);

            $mapped[] = [
                'step_index' => is_int($step['step_index'] ?? null) ? $step['step_index'] : null,
                'agent_class' => self::scalar($step['agent_class'] ?? null),
                // What the flow annotates: the node's role (coordinator/worker) and
                // the routing decision (e.g. the category the coordinator picked).
                'role' => self::scalar($meta['node_role'] ?? null),
                'decision' => self::scalar($meta['category'] ?? null),
                'tokens' => $stepTokens > 0 ? $stepTokens : null,
                'input' => self::sealed($step, 'input'),
                'output' => self::sealed($step, 'output'),
            ];
        }

        return $mapped;
    }

    /**
     * Render a flat `<field>` / `<field>_available` sealed pair; the three-state
     * mapping (and the ciphertext-escape guarantee) lives in {@see render()}.
     *
     * @param  array<string, mixed>  $row
     */
    private static function sealed(array $row, string $field): string
    {
        return self::render(DisplayField::fromRow($row, $field));
    }

    /**
     * Map a {@see DisplayField}'s three outcomes to a display string. Ciphertext
     * never escapes: a still-`sw0:` value is flagged unavailable by DisplayField.
     */
    private static function render(DisplayField $displayField): string
    {
        if ($displayField->isAvailable()) {
            return (string) $displayField->value;
        }

        // Not renderable: an explicit false flag (or a masked sw0: value) means
        // undecryptable; otherwise the field was simply absent/empty.
        return $displayField->available === false ? self::UNAVAILABLE : self::NONE;
    }

    private static function scalar(mixed $value): ?string
    {
        return is_scalar($value) ? (string) $value : null;
    }
}
