<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarmFilament\Support;

use BuiltByBerry\LaravelSwarm\Persistence\DatabaseDurableRunStore;
use Illuminate\Support\Str;

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
     * The run/step flow for a run-history detail. Each recorded step becomes a
     * node; the edges are derived from the run's **topology**, because run-history
     * steps carry order + role/decision but no explicit parent/branch edges:
     *
     * - **sequential** (and any unknown topology): a left-to-right chain.
     * - **parallel**: a synthetic run-origin fanning out to every step — the
     *   runner merges the branch results into the response, so there is no join
     *   agent to draw.
     * - **hierarchical**: the coordinator step routes to every worker step.
     * - **static_hierarchical**: the true DAG (parallel branches, joins) lives
     *   only in a durable run's branch records — see {@see fromDurable()}. From a
     *   non-durable run's flat step list we can only honestly show the recorded
     *   execution order as a chain rather than invent a shape we cannot verify.
     *
     * @param  array<string, mixed>  $presented  a {@see RunDisplayPresenter::present()} record
     * @param  array<int, array<string, mixed>>  $facets  per-step memory/tool facets keyed by step index ({@see MemoryFacets::forRun()})
     * @return array{nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>}
     */
    public static function fromRun(array $presented, array $facets = []): array
    {
        $steps = is_array($presented['steps'] ?? null) ? $presented['steps'] : [];
        $runStatus = is_string($presented['status'] ?? null) ? $presented['status'] : null;
        $topology = is_string($presented['topology'] ?? null) ? $presented['topology'] : null;

        $nodes = [];
        $ids = [];    // ordinal => node id, in recorded order
        $roles = [];  // ordinal => role (e.g. 'coordinator')

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
                // Everything about the step, folded into the flow's click-through:
                // I/O (already sealed-rendered upstream by RunDisplayPresenter), the
                // memory it wrote/saw, and the tools it called. Run-history steps
                // carry no per-step duration/attempts, so those stay null here — the
                // detail SHAPE is kept consistent across every builder regardless.
                ...self::detail([
                    'summary' => self::summarize($output),
                    'tokens' => is_int($step['tokens'] ?? null) ? $step['tokens'] : null,
                    'detail_input' => self::str($step['input'] ?? null),
                    'detail_output' => $output,
                    'detail_wrote' => is_array($facet['wrote'] ?? null) ? array_values($facet['wrote']) : [],
                    'detail_memory' => is_array($facet['entries'] ?? null) ? array_values($facet['entries']) : [],
                    'detail_tools' => is_array($facet['tools'] ?? null) ? array_values($facet['tools']) : [],
                ]),
            ];
            $ids[$i] = $id;
            $roles[$i] = $role;
        }

        [$origin, $edges] = self::wireRunTopology($topology, $ids, $roles, $runStatus, $presented);

        if ($origin !== null) {
            array_unshift($nodes, $origin);
        }

        return ['nodes' => $nodes, 'edges' => $edges];
    }

    /**
     * Derive the flow edges (and an optional synthetic origin node) from the run's
     * topology. See {@see fromRun()} for the per-topology rationale.
     *
     * @param  array<int, string>  $ids  ordinal => node id, in recorded order
     * @param  array<int, ?string>  $roles  ordinal => role
     * @param  array<string, mixed>  $presented
     * @return array{0: ?array<string, mixed>, 1: list<array<string, mixed>>}
     */
    private static function wireRunTopology(?string $topology, array $ids, array $roles, ?string $runStatus, array $presented): array
    {
        if ($ids === []) {
            return [null, []];
        }

        if ($topology === 'parallel') {
            // A synthetic run node fans out to every branch — the branches share
            // the original task and run concurrently, then merge into the response.
            $swarm = is_string($presented['swarm_class'] ?? null) ? class_basename($presented['swarm_class']) : 'Run';
            $origin = self::syntheticNode('run-origin', $swarm, 'fan-out', $runStatus);

            $edges = [];
            foreach ($ids as $id) {
                $edges[] = ['from' => 'run-origin', 'to' => $id, 'kind' => 'parallel'];
            }

            return [$origin, $edges];
        }

        if ($topology === 'hierarchical') {
            // The coordinator (the step tagged as such, else the first) routes to
            // every worker step.
            $coordinatorOrdinal = null;
            foreach ($roles as $ordinal => $role) {
                if ($role === 'coordinator') {
                    $coordinatorOrdinal = $ordinal;

                    break;
                }
            }
            $coordinatorOrdinal ??= array_key_first($ids);
            $coordinatorId = $ids[$coordinatorOrdinal];

            $edges = [];
            foreach ($ids as $ordinal => $id) {
                if ($ordinal !== $coordinatorOrdinal) {
                    $edges[] = ['from' => $coordinatorId, 'to' => $id, 'kind' => 'route'];
                }
            }

            return [null, $edges];
        }

        // sequential, static_hierarchical, and any unknown topology: honest chain.
        $edges = [];
        $prev = null;
        foreach ($ids as $id) {
            if ($prev !== null) {
                $edges[] = ['from' => $prev, 'to' => $id, 'kind' => 'sequence'];
            }
            $prev = $id;
        }

        return [null, $edges];
    }

    /**
     * A structural node with no per-step detail (e.g. the parallel run-origin), so
     * it renders as a clean box and its click-through detail is empty.
     *
     * @return array<string, mixed>
     */
    private static function syntheticNode(string $id, string $label, string $sublabel, ?string $status): array
    {
        return [
            'id' => $id,
            'label' => $label,
            'sublabel' => $sublabel,
            'status' => $status,
            ...self::detail(),
        ];
    }

    /**
     * The canonical per-node click-through detail block, applied uniformly across
     * every builder ({@see fromRun()}, {@see fromDurable()}, {@see fromRoutePlan()})
     * so the graph partial renders one consistent shape whatever the source surface.
     * A builder overrides only the facets it actually has; the rest degrade to
     * null/empty rather than being absent.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private static function detail(array $overrides = []): array
    {
        return array_merge([
            'summary' => null,
            'tokens' => null,
            'detail_input' => null,
            'detail_output' => null,
            'detail_duration' => null,
            'detail_attempts' => null,
            'detail_wrote' => [],
            'detail_memory' => [],
            'detail_tools' => [],
        ], $overrides);
    }

    /**
     * The click-through detail for one display-decrypted durable branch row
     * ({@see DatabaseDurableRunStore::mapBranchForDisplay()}).
     *
     * SEALED INVARIANT: the branch's `input`/`output` are decrypted payloads
     * carrying `*_available` flags — they are routed through the SINGLE companion
     * sealed chokepoint ({@see RunDisplayPresenter::renderField()}) so a branch's
     * value (or a nested `sw0:` leaf) degrades to the same three-state string as
     * the run/step infolist and never renders raw. Memory facets join by
     * `step_index` — the same key {@see MemoryFacets::forRun()} snapshots under.
     *
     * @param  array<string, mixed>  $branch  a mapBranchForDisplay() row
     * @param  array<int, array<string, mixed>>  $facets
     * @return array<string, mixed>
     */
    private static function branchDetail(array $branch, array $facets): array
    {
        $output = RunDisplayPresenter::renderField($branch, 'output');
        $stepIndex = is_int($branch['step_index'] ?? null) ? $branch['step_index'] : null;
        $facet = $stepIndex !== null && isset($facets[$stepIndex]) && is_array($facets[$stepIndex]) ? $facets[$stepIndex] : [];
        $attempts = is_int($branch['attempts'] ?? null) ? $branch['attempts'] : null;

        return self::detail([
            'summary' => self::summarize($output),
            'tokens' => self::usageTokens(is_array($branch['usage'] ?? null) ? $branch['usage'] : []),
            'detail_input' => RunDisplayPresenter::renderField($branch, 'input'),
            'detail_output' => $output,
            'detail_duration' => is_int($branch['duration_ms'] ?? null) ? self::formatDuration($branch['duration_ms']) : null,
            'detail_attempts' => $attempts !== null && $attempts > 0 ? $attempts : null,
            'detail_wrote' => is_array($facet['wrote'] ?? null) ? array_values($facet['wrote']) : [],
            'detail_memory' => is_array($facet['entries'] ?? null) ? array_values($facet['entries']) : [],
            'detail_tools' => is_array($facet['tools'] ?? null) ? array_values($facet['tools']) : [],
        ]);
    }

    /**
     * A node_id-keyed map of branch detail, so a durable run's authored route plan
     * ({@see fromRoutePlan()}) can graft each worker's real execution I/O onto its
     * declared node. Branch `node_id` matches the plan node id.
     *
     * @param  array<int, array<string, mixed>>  $facets
     * @return array<string, array<string, mixed>>
     */
    private static function branchDetailMap(mixed $branches, array $facets): array
    {
        $map = [];
        foreach (self::rows($branches) as $branch) {
            $nodeId = self::str($branch['node_id'] ?? null);
            if ($nodeId !== null) {
                $map[$nodeId] = self::branchDetail($branch, $facets);
            }
        }

        return $map;
    }

    /**
     * Total prompt+completion tokens from a usage array, or null when there is
     * nothing to report — mirrors {@see RunDisplayPresenter} so the flow shows
     * tokens only when real.
     *
     * @param  array<string, mixed>  $usage
     */
    private static function usageTokens(array $usage): ?int
    {
        $prompt = is_numeric($usage['prompt_tokens'] ?? null) ? (int) $usage['prompt_tokens'] : 0;
        $completion = is_numeric($usage['completion_tokens'] ?? null) ? (int) $usage['completion_tokens'] : 0;
        $total = $prompt + $completion;

        return $total > 0 ? $total : null;
    }

    /**
     * A durable branch's wall-clock as a compact human string (sub-second stays in
     * ms; a second or more rounds to one decimal).
     */
    private static function formatDuration(int $ms): string
    {
        return $ms < 1000 ? $ms.'ms' : round($ms / 1000, 1).'s';
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

        return (string) Str::of($output)
            ->replaceMatches('/^\[[^\]]+\]\s*/', '')
            ->replaceMatches('/\s+/', ' ')
            ->trim();
    }

    /**
     * The declared route plan of a (static-)hierarchical run — the TRUE DAG.
     *
     * A durable hierarchical run persists its `route_plan` (`start_at` + `nodes`),
     * which carries the real edges a flat run-history step list cannot: the
     * parallel fan-out, the join back into the next worker, and the finish node.
     * Rendering the plan directly gives the authored topology exactly (e.g. a
     * gather → (a ∥ b) → write → finish diamond), with per-node execution status
     * overlaid from `completed_node_ids`.
     *
     * Edge rules by node type:
     * - **worker / rollup**: an edge to its `next` node.
     * - **parallel**: an edge to each `branch`, and — since the branches rejoin
     *   before the parallel node's `next` — an edge from each branch to `next`.
     * - **finish**: terminal; `output_from` is data flow, not a control edge.
     *
     * The authored plan carries only the topology; the real per-node execution I/O
     * (input, output, tokens, duration, attempts, memory) lives in the durable
     * branch rows. Pass those `$branches` (display-decrypted mapBranchForDisplay
     * rows) and the run's memory `$facets` and each worker node is grafted with its
     * branch detail — routed through the sealed chokepoint by {@see branchDetail()}
     * — so the authored DAG is also the richest detail surface. Structural nodes
     * (parallel/finish) have no branch and keep the empty detail shape.
     *
     * @param  array{start_at?: string, nodes?: array<string, array<string, mixed>>}  $plan
     * @param  list<string>  $completedNodeIds
     * @param  mixed  $branches  display-decrypted durable branch rows (node_id-carrying)
     * @param  array<int, array<string, mixed>>  $facets  per-step memory facets ({@see MemoryFacets::forRun()})
     * @return array{nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>}
     */
    public static function fromRoutePlan(array $plan, array $completedNodeIds = [], ?string $runStatus = null, mixed $branches = [], array $facets = []): array
    {
        $planNodes = is_array($plan['nodes'] ?? null) ? $plan['nodes'] : [];
        $completed = array_flip(array_values(array_filter($completedNodeIds, 'is_string')));
        $detailByNode = self::branchDetailMap($branches, $facets);

        $nodes = [];
        $edges = [];

        foreach ($planNodes as $nodeId => $node) {
            if (! is_string($nodeId) || ! is_array($node)) {
                continue;
            }
            $type = self::str($node['type'] ?? null) ?? 'worker';
            $agent = self::str($node['agent'] ?? null);

            $nodes[] = [
                'id' => 'plan:'.$nodeId,
                'label' => $agent !== null ? class_basename($agent) : ucfirst($nodeId),
                // Workers read as their node id; structural nodes name their type.
                'sublabel' => $type === 'worker' ? $nodeId : $type,
                'status' => isset($completed[$nodeId]) ? 'completed' : $runStatus,
                // The worker's real branch execution detail when it ran; otherwise
                // the empty detail shape (structural nodes, or a node not yet run).
                ...($detailByNode[$nodeId] ?? self::detail()),
            ];

            if ($type === 'parallel') {
                $branches = is_array($node['branches'] ?? null) ? array_values(array_filter($node['branches'], 'is_string')) : [];
                $next = self::str($node['next'] ?? null);
                foreach ($branches as $branch) {
                    $edges[] = ['from' => 'plan:'.$nodeId, 'to' => 'plan:'.$branch, 'kind' => 'branch'];
                    if ($next !== null) {
                        $edges[] = ['from' => 'plan:'.$branch, 'to' => 'plan:'.$next, 'kind' => 'join'];
                    }
                }

                continue;
            }

            $next = self::str($node['next'] ?? null);
            if ($next !== null) {
                $edges[] = ['from' => 'plan:'.$nodeId, 'to' => 'plan:'.$next, 'kind' => 'sequence'];
            }
        }

        return ['nodes' => $nodes, 'edges' => $edges];
    }

    /**
     * The durable execution DAG: a root run node, the hierarchical/parallel
     * branches wired by `parent_node_id`, any node outputs not covered by a branch
     * chained in recorded order, and spawned child runs hung off the root. This is
     * the richest workflow shape — the tree-of-nodes the old nested tables hid.
     *
     * Every per-node input/output grafted here is display-decrypted durable data,
     * so it is routed through the sealed chokepoint ({@see branchDetail()} /
     * {@see RunDisplayPresenter::renderField()}) — a branch/child/node-output value
     * (or a nested `sw0:` leaf) never renders raw. Memory facets join by step_index.
     *
     * @param  array<string, mixed>  $presented  a durable-run display record (summary + branches + children + node_outputs)
     * @param  array<int, array<string, mixed>>  $facets  per-step memory facets ({@see MemoryFacets::forRun()})
     * @return array{nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>}
     */
    public static function fromDurable(array $presented, array $facets = []): array
    {
        $summary = is_array($presented['summary'] ?? null) ? $presented['summary'] : [];
        $rootStatus = self::firstString($summary, ['status']);
        $rootLabel = self::firstString($summary, ['swarm_class', 'swarm', 'topology']) ?? 'run';

        $nodes = [['id' => 'run', 'label' => class_basename($rootLabel), 'sublabel' => 'run', 'status' => $rootStatus, ...self::detail()]];
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
                // The branch's real execution I/O, sealed-rendered — the per-node
                // fields the old nested tables carried but this DAG used to drop.
                ...self::branchDetail($b, $facets),
            ];
            $parent = self::str($b['parent_node_id'] ?? null);
            $edges[] = ['from' => $parent !== null ? 'node:'.$parent : 'run', 'to' => 'node:'.$nodeId, 'kind' => 'branch'];
        }

        // Node outputs not represented by a branch — chain them in recorded order so
        // a sequential durable run reads as a flow rather than a star. The stored
        // output is display-decrypted; render it through the sealed chokepoint.
        $prevOutput = 'run';
        foreach (self::rows($presented['node_outputs'] ?? null) as $o) {
            $nodeId = self::str($o['node_id'] ?? null);
            if ($nodeId === null || isset($branchNodeIds[$nodeId])) {
                continue;
            }
            $output = RunDisplayPresenter::renderField($o, 'output');
            $nodes[] = [
                'id' => 'node:'.$nodeId,
                'label' => $nodeId,
                'sublabel' => 'node',
                'status' => null,
                ...self::detail(['summary' => self::summarize($output), 'detail_output' => $output]),
            ];
            $edges[] = ['from' => $prevOutput, 'to' => 'node:'.$nodeId, 'kind' => 'node'];
            $prevOutput = 'node:'.$nodeId;
        }

        foreach (self::rows($presented['children'] ?? null) as $c) {
            $childId = self::str($c['child_run_id'] ?? null);
            if ($childId === null) {
                continue;
            }
            $swarm = self::str($c['child_swarm_class'] ?? null);
            // A spawned child run surfaces as a node; its context (input) and output
            // are display-decrypted, so both go through the sealed chokepoint.
            $childOutput = RunDisplayPresenter::renderField($c, 'output');
            $nodes[] = [
                'id' => 'child:'.$childId,
                'label' => $swarm !== null ? class_basename($swarm) : 'child',
                'sublabel' => 'child run',
                'status' => self::str($c['status'] ?? null),
                ...self::detail([
                    'summary' => self::summarize($childOutput),
                    'detail_input' => RunDisplayPresenter::renderField($c, 'context_payload'),
                    'detail_output' => $childOutput,
                ]),
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
