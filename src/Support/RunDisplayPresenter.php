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
     * @return array{run_id: ?string, swarm_class: ?string, topology: ?string, status: ?string, started_at: mixed, finished_at: mixed, context: string, output: string, steps: list<array{step_index: ?int, agent_class: ?string, input: string, output: string}>}
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
     * @return list<array{step_index: ?int, agent_class: ?string, input: string, output: string}>
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
