<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarmFilament\Support;

/**
 * Lays out a swarm run as a left-to-right workflow graph — the visual centerpiece
 * that turns the "nested lists" detail views into an actual workflow shape.
 *
 * Pure and container-free: it takes plain node + edge arrays and returns positioned
 * nodes and SVG edge paths (a layered / Sugiyama-lite layout — layer = longest path
 * from a root, ordered first-seen within a layer). The Blade partial renders the
 * result as inline `<svg>`; no JS graph library, so the layout is unit-testable and
 * the surface stays dependency-free and CSP-safe. Cycle-safe (a bounded relaxation
 * pass) and tolerant of dangling edges (endpoints must resolve, or the edge drops).
 *
 * @phpstan-type GraphNode array{id: string, label: string, sublabel: ?string, status: ?string}
 * @phpstan-type LaidNode array{id: string, label: string, sublabel: ?string, status: ?string, x: int, y: int, w: int, h: int}
 * @phpstan-type LaidEdge array{from: string, to: string, kind: string, d: string}
 */
final class WorkflowGraphPresenter
{
    private const NODE_W = 200;

    private const NODE_H = 60;

    private const H_GAP = 72;

    private const V_GAP = 26;

    private const PAD = 20;

    /**
     * @param  list<array<string, mixed>>  $nodes  each {id, label, sublabel?, status?}
     * @param  list<array<string, mixed>>  $edges  each {from, to, kind?}
     * @return array{nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>, width: int, height: int, empty: bool}
     */
    public static function present(array $nodes, array $edges): array
    {
        /** @var array<string, GraphNode> $byId */
        $byId = [];
        foreach ($nodes as $node) {
            $id = self::str($node['id'] ?? null);
            if ($id === null || $id === '' || isset($byId[$id])) {
                continue;
            }
            $byId[$id] = [
                'id' => $id,
                'label' => self::str($node['label'] ?? null) ?? $id,
                'sublabel' => self::str($node['sublabel'] ?? null),
                'status' => self::str($node['status'] ?? null),
            ];
        }

        if ($byId === []) {
            return ['nodes' => [], 'edges' => [], 'width' => 0, 'height' => 0, 'empty' => true];
        }

        // Keep only edges whose endpoints resolve to real nodes (drop dangling).
        $validEdges = [];
        foreach ($edges as $edge) {
            $from = self::str($edge['from'] ?? null);
            $to = self::str($edge['to'] ?? null);
            if ($from === null || $to === null || $from === $to || ! isset($byId[$from], $byId[$to])) {
                continue;
            }
            $validEdges[] = ['from' => $from, 'to' => $to, 'kind' => self::str($edge['kind'] ?? null) ?? 'edge'];
        }

        // Layer = longest path from a root. Bounded relaxation (|nodes| passes) is
        // cycle-safe: a back-edge simply stops improving once the cap is hit.
        $layer = array_fill_keys(array_keys($byId), 0);
        $passes = count($byId);
        for ($i = 0; $i < $passes; $i++) {
            $changed = false;
            foreach ($validEdges as $edge) {
                if ($layer[$edge['to']] < $layer[$edge['from']] + 1) {
                    $layer[$edge['to']] = $layer[$edge['from']] + 1;
                    $changed = true;
                }
            }
            if (! $changed) {
                break;
            }
        }

        // Group by layer (column), preserving first-seen order within a column.
        $columns = [];
        foreach ($byId as $id => $_) {
            $columns[$layer[$id]][] = $id;
        }
        ksort($columns);

        $maxRows = 0;
        foreach ($columns as $ids) {
            $maxRows = max($maxRows, count($ids));
        }

        $height = self::PAD * 2 + $maxRows * self::NODE_H + max(0, $maxRows - 1) * self::V_GAP;
        $colCount = count($columns) === 0 ? 1 : max(array_keys($columns)) + 1;
        $width = self::PAD * 2 + $colCount * self::NODE_W + max(0, $colCount - 1) * self::H_GAP;

        /** @var array<string, LaidNode> $laid */
        $laid = [];
        foreach ($columns as $col => $ids) {
            $count = count($ids);
            $blockHeight = $count * self::NODE_H + max(0, $count - 1) * self::V_GAP;
            $startY = self::PAD + (int) max(0, (($height - self::PAD * 2) - $blockHeight) / 2);
            $row = 0;
            foreach ($ids as $id) {
                $laid[$id] = $byId[$id] + [
                    'x' => self::PAD + $col * (self::NODE_W + self::H_GAP),
                    'y' => $startY + $row * (self::NODE_H + self::V_GAP),
                    'w' => self::NODE_W,
                    'h' => self::NODE_H,
                ];
                $row++;
            }
        }

        $outEdges = [];
        foreach ($validEdges as $edge) {
            $a = $laid[$edge['from']];
            $b = $laid[$edge['to']];
            $x1 = $a['x'] + $a['w'];
            $y1 = $a['y'] + intdiv($a['h'], 2);
            $x2 = $b['x'];
            $y2 = $b['y'] + intdiv($b['h'], 2);
            $mx = intdiv($x1 + $x2, 2);
            $outEdges[] = [
                'from' => $edge['from'], 'to' => $edge['to'], 'kind' => $edge['kind'],
                'd' => sprintf('M %d %d C %d %d %d %d %d %d', $x1, $y1, $mx, $y1, $mx, $y2, $x2, $y2),
            ];
        }

        return [
            'nodes' => array_values($laid),
            'edges' => $outEdges,
            'width' => $width,
            'height' => max($height, self::PAD * 2 + self::NODE_H),
            'empty' => false,
        ];
    }

    private static function str(mixed $value): ?string
    {
        return is_scalar($value) ? (string) $value : null;
    }
}
