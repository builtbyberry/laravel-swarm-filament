<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarmFilament\Support;

/**
 * Builds the node + edge sets that {@see WorkflowGraphPresenter} lays out, from the
 * display records the read surfaces already resolve. Pure — no container, no store.
 *
 * Keeping the "what is a node / what is an edge" mapping here (per surface) separate
 * from the geometry ({@see WorkflowGraphPresenter}) keeps both unit-testable.
 */
final class RunGraph
{
    /**
     * The run/step flow for a run-history detail: the steps as a left-to-right
     * chain (coordinator → … → final agent). Run history carries no per-step
     * topology edges, so a sequential chain is the honest, readable shape.
     *
     * @param  array<string, mixed>  $presented  a {@see RunDisplayPresenter::present()} record
     * @param  array<int, array<string, mixed>>  $facets  per-step memory/tool facets keyed by step index ({@see MemoryFacets::forRun()})
     * @return array{nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>}
     */
    public static function fromRun(array $presented, array $facets = []): array
    {
        $steps = is_array($presented['steps'] ?? null) ? $presented['steps'] : [];
        $runStatus = is_string($presented['status'] ?? null) ? $presented['status'] : null;

        $nodes = [];
        $edges = [];
        $prev = null;

        foreach (array_values($steps) as $i => $step) {
            if (! is_array($step)) {
                continue;
            }
            $stepIndex = $step['step_index'] ?? $i;
            $id = 'step-'.$stepIndex;
            $agent = is_string($step['agent_class'] ?? null) ? class_basename($step['agent_class']) : 'Step';
            $role = self::str($step['role'] ?? null);
            $decision = self::str($step['decision'] ?? null);
            $output = self::str($step['output'] ?? null);
            $facet = is_int($stepIndex) && isset($facets[$stepIndex]) && is_array($facets[$stepIndex]) ? $facets[$stepIndex] : [];

            $nodes[] = [
                'id' => $id,
                'label' => $agent,
                // The node's job in the flow: the routing decision that reached it,
                // else its role, else the step number.
                'sublabel' => $decision !== null ? 'via '.$decision : ($role ?? 'step '.$stepIndex),
                'status' => $runStatus,
                // What it actually did — the line that makes the flow legible.
                'summary' => self::summarize($output),
                'tokens' => is_int($step['tokens'] ?? null) ? $step['tokens'] : null,
                // Everything about the step, folded into the flow's click-through:
                // I/O, the memory it wrote/saw, and the tools it called.
                'detail_input' => self::str($step['input'] ?? null),
                'detail_output' => $output,
                'detail_wrote' => is_array($facet['wrote'] ?? null) ? array_values($facet['wrote']) : [],
                'detail_memory' => is_array($facet['entries'] ?? null) ? array_values($facet['entries']) : [],
                'detail_tools' => is_array($facet['tools'] ?? null) ? array_values($facet['tools']) : [],
            ];
            if ($prev !== null) {
                $edges[] = ['from' => $prev, 'to' => $id, 'kind' => 'sequence'];
            }
            $prev = $id;
        }

        return ['nodes' => $nodes, 'edges' => $edges];
    }

    /**
     * A one-line gist of an agent's output for the node card: drop a leading
     * "[Agent] " label and any "answer this …:" preamble, collapse whitespace,
     * and clip. The full text lives in the click-through detail.
     */
    private static function summarize(?string $output): ?string
    {
        if ($output === null || $output === '' || in_array($output, ['unavailable', 'none', '[redacted]'], true)) {
            return $output === null ? null : $output;
        }

        $text = (string) preg_replace('/^\[[^\]]+\]\s*/', '', $output);
        $text = (string) preg_replace('/\s+/', ' ', $text);

        return trim($text);
    }

    /**
     * The durable execution DAG: a root run node, the hierarchical/parallel
     * branches wired by `parent_node_id`, any node outputs not covered by a branch
     * chained in recorded order, and spawned child runs hung off the root. This is
     * the richest workflow shape — the tree-of-nodes the old nested tables hid.
     *
     * @param  array<string, mixed>  $presented  a durable-run display record (summary + branches + children + node_outputs)
     * @return array{nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>}
     */
    public static function fromDurable(array $presented): array
    {
        $summary = is_array($presented['summary'] ?? null) ? $presented['summary'] : [];
        $rootStatus = self::firstString($summary, ['status']);
        $rootLabel = self::firstString($summary, ['swarm_class', 'swarm', 'topology']) ?? 'run';

        $nodes = [['id' => 'run', 'label' => class_basename($rootLabel), 'sublabel' => 'run', 'status' => $rootStatus]];
        $edges = [];
        $branchNodeIds = [];

        foreach (self::rows($presented['branches'] ?? null) as $b) {
            $nodeId = self::str($b['node_id'] ?? null);
            if ($nodeId === null) {
                continue;
            }
            $branchNodeIds[$nodeId] = true;
            $agent = self::str($b['agent_class'] ?? null);
            $nodes[] = [
                'id' => 'node:'.$nodeId,
                'label' => $agent !== null ? class_basename($agent) : $nodeId,
                'sublabel' => $nodeId,
                'status' => self::str($b['status'] ?? null),
            ];
            $parent = self::str($b['parent_node_id'] ?? null);
            $edges[] = ['from' => $parent !== null ? 'node:'.$parent : 'run', 'to' => 'node:'.$nodeId, 'kind' => 'branch'];
        }

        // Node outputs not represented by a branch — chain them in recorded order so
        // a sequential durable run reads as a flow rather than a star.
        $prevOutput = 'run';
        foreach (self::rows($presented['node_outputs'] ?? null) as $o) {
            $nodeId = self::str($o['node_id'] ?? null);
            if ($nodeId === null || isset($branchNodeIds[$nodeId])) {
                continue;
            }
            $nodes[] = ['id' => 'node:'.$nodeId, 'label' => $nodeId, 'sublabel' => 'node', 'status' => null];
            $edges[] = ['from' => $prevOutput, 'to' => 'node:'.$nodeId, 'kind' => 'node'];
            $prevOutput = 'node:'.$nodeId;
        }

        foreach (self::rows($presented['children'] ?? null) as $c) {
            $childId = self::str($c['child_run_id'] ?? null);
            if ($childId === null) {
                continue;
            }
            $swarm = self::str($c['child_swarm_class'] ?? null);
            $nodes[] = [
                'id' => 'child:'.$childId,
                'label' => $swarm !== null ? class_basename($swarm) : 'child',
                'sublabel' => 'child run',
                'status' => self::str($c['status'] ?? null),
            ];
            $edges[] = ['from' => 'run', 'to' => 'child:'.$childId, 'kind' => 'child'];
        }

        return ['nodes' => $nodes, 'edges' => $edges];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function rows(mixed $rows): array
    {
        if (! is_array($rows)) {
            return [];
        }

        return array_values(array_filter($rows, 'is_array'));
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  list<string>  $keys
     */
    private static function firstString(array $row, array $keys): ?string
    {
        foreach ($keys as $key) {
            $v = self::str($row[$key] ?? null);
            if ($v !== null && $v !== '') {
                return $v;
            }
        }

        return null;
    }

    private static function str(mixed $value): ?string
    {
        return is_scalar($value) ? (string) $value : null;
    }
}
