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
     * @return array{nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>}
     */
    public static function fromRun(array $presented): array
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
            $id = 'step-'.($step['step_index'] ?? $i);
            $agent = is_string($step['agent_class'] ?? null) ? class_basename($step['agent_class']) : 'Step';
            $nodes[] = [
                'id' => $id,
                'label' => $agent,
                'sublabel' => 'step '.($step['step_index'] ?? $i),
                // Historical steps: the run's terminal status colors the flow.
                'status' => $runStatus,
            ];
            if ($prev !== null) {
                $edges[] = ['from' => $prev, 'to' => $id, 'kind' => 'sequence'];
            }
            $prev = $id;
        }

        return ['nodes' => $nodes, 'edges' => $edges];
    }

    /**
     * The durable execution DAG: a root run node, the hierarchical/parallel
     * branches wired by `parent_node_id`, any node outputs not covered by a branch
     * chained in recorded order, and spawned child runs hung off the root. This is
     * the richest workflow shape — the tree-of-nodes the old nested tables hid.
     *
     * @param  array<string, mixed>  $presented  a {@see DurableRunPresenter::present()} record
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
