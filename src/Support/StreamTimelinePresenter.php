<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarmFilament\Support;

use BuiltByBerry\LaravelSwarm\Contracts\StreamEventStore;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmStreamEvent;

/**
 * Folds a run's append-only causal log into a per-node timeline for display.
 *
 * The log is read through the public {@see StreamEventStore}
 * contract, whose `events()` yields typed {@see SwarmStreamEvent}s in causal
 * (append) order. This presenter is the single, pure, container-free place that
 * turns that stream into a render-ready structure:
 *
 * - **Grouped by node.** Every event carries a structural `node_id` (#284); events
 *   are bucketed by it, in first-seen order, preserving each node's own causal
 *   ordering. Events with no `node_id` (top-level, or a pre-grammar #282-era log)
 *   collect under a single synthetic top-level group.
 * - **Void-edge markers.** A `swarm_causal_void_edge` event never deletes its
 *   target; it records that an earlier event was superseded / replaced / abandoned
 *   / rolled-up / re-executed. Each void-edge is surfaced as a first-class marker
 *   row, and the event it targets is annotated as voided. A void-edge whose target
 *   is absent from the window is the causal-log concept of a **missing / nulled
 *   predecessor** — it is rendered as a distinct marker (flagged, and coloured
 *   `danger`) rather than silently dropped. When several edges target one event
 *   the most recent (last, in causal order) wins — matching core's read-time fold.
 * - **Ciphertext-defended payloads.** Payloads here are plaintext-but-capture-
 *   redacted (no cipher), but as defense in depth every string leaf is routed
 *   through {@see DisplayField}, so a value that still looks like `sw0:` ciphertext
 *   is masked and never rendered — whatever an upstream write did.
 *
 * Pure: it never touches the container, the store, or Filament, so the fold is
 * unit-testable directly. {@see present()} adapts the store's typed event stream;
 * {@see build()} is the array-in / array-out core the tests exercise.
 */
final class StreamTimelinePresenter
{
    /**
     * The event type of a causal void-edge, matched from the payload rather than
     * imported from core's event class — the companion reads the log by its stable
     * string keys, never by coupling to the event objects.
     */
    private const VOID_EDGE_TYPE = 'swarm_causal_void_edge';

    /**
     * Synthetic bucket key for events carrying no `node_id`. A real node id is a
     * UUID, so this NUL-prefixed sentinel can never collide with one.
     */
    private const TOP_LEVEL_KEY = "\0top-level";

    /**
     * The placeholder a masked (still-sealed) payload leaf renders as. Matches the
     * sibling {@see RunDisplayPresenter} so the two surfaces read identically.
     */
    private const UNAVAILABLE = 'unavailable';

    /**
     * Read a run's causal log through the {@see StreamEventStore}
     * contract's typed event stream and fold it into the timeline.
     *
     * @param  iterable<SwarmStreamEvent>  $events  typically `StreamEventStore::events($runId)`
     * @return array{event_count: int, void_edge_count: int, node_count: int, nodes: list<array{node_id: ?string, is_top_level: bool, label: string, event_count: int, events: list<array<string, mixed>>}>}
     */
    public static function present(iterable $events): array
    {
        $payloads = [];

        foreach ($events as $event) {
            $payloads[] = $event->toArray();
        }

        return self::build($payloads);
    }

    /**
     * The pure timeline fold: an ordered list of causal-log payloads in, a
     * grouped, void-annotated, render-ready timeline out.
     *
     * @param  list<array<string, mixed>>  $payloads  causal-log event payloads, in causal order
     * @return array{event_count: int, void_edge_count: int, node_count: int, nodes: list<array{node_id: ?string, is_top_level: bool, label: string, event_count: int, events: list<array<string, mixed>>}>}
     */
    public static function build(array $payloads): array
    {
        // Pass 1 — locate every present event's node, and index void-edges by the
        // event they target (last edge wins, matching core's clean-view fold).
        $nodeOfEvent = [];
        $presentIds = [];
        $voidByTarget = [];

        foreach ($payloads as $payload) {
            if (! is_array($payload)) {
                continue;
            }

            if (self::stringField($payload, 'type') === self::VOID_EDGE_TYPE) {
                $target = self::stringField($payload, 'target_event_id');

                if ($target !== null) {
                    $voidByTarget[$target][] = [
                        'void_type' => self::stringField($payload, 'void_type') ?? '',
                        'reason' => self::stringField($payload, 'reason') ?? '',
                    ];
                }

                continue;
            }

            $id = self::stringField($payload, 'id');

            if ($id !== null) {
                $presentIds[$id] = true;
                $nodeOfEvent[$id] = self::nodeKey($payload);
            }
        }

        // Pass 2 — collect rows per bucket (in first-seen order) and count events.
        /** @var list<string> $order */
        $order = [];
        /** @var array<string, list<array<string, mixed>>> $rowsByBucket */
        $rowsByBucket = [];
        /** @var array<string, int> $eventsByBucket */
        $eventsByBucket = [];
        $eventCount = 0;
        $voidEdgeCount = 0;

        foreach ($payloads as $payload) {
            if (! is_array($payload)) {
                continue;
            }

            if (self::stringField($payload, 'type') === self::VOID_EDGE_TYPE) {
                $target = self::stringField($payload, 'target_event_id');
                $targetPresent = $target !== null && isset($presentIds[$target]);

                // A void-edge sits with the node it voids when that node is present,
                // so the marker reads next to its target; a missing/nulled
                // predecessor has no such home, so it falls to the void-edge's own
                // node (or the top-level bucket).
                $bucketKey = $targetPresent
                    ? $nodeOfEvent[$target]
                    : self::nodeKey($payload);

                self::registerBucket($order, $eventsByBucket, $bucketKey);
                $rowsByBucket[$bucketKey][] = self::voidRow($payload, $targetPresent);
                $voidEdgeCount++;

                continue;
            }

            $bucketKey = self::nodeKey($payload);
            self::registerBucket($order, $eventsByBucket, $bucketKey);

            $id = self::stringField($payload, 'id');
            $annotation = $id !== null && isset($voidByTarget[$id])
                ? $voidByTarget[$id][count($voidByTarget[$id]) - 1]
                : null;

            $rowsByBucket[$bucketKey][] = self::eventRow($payload, $annotation);
            $eventsByBucket[$bucketKey]++;
            $eventCount++;
        }

        // Assemble the node shapes in first-seen order.
        $nodes = [];

        foreach ($order as $bucketKey) {
            $isTopLevel = $bucketKey === self::TOP_LEVEL_KEY;
            $nodeId = $isTopLevel ? null : $bucketKey;

            $nodes[] = [
                'node_id' => $nodeId,
                'is_top_level' => $isTopLevel,
                'label' => $isTopLevel ? 'Top-level events' : 'Node '.self::shortId($nodeId),
                'event_count' => $eventsByBucket[$bucketKey],
                'events' => $rowsByBucket[$bucketKey] ?? [],
            ];
        }

        return [
            'event_count' => $eventCount,
            'void_edge_count' => $voidEdgeCount,
            'node_count' => count($nodes),
            'nodes' => $nodes,
        ];
    }

    /**
     * Record a bucket's first appearance (fixing its position) and seed its event
     * counter. Idempotent — a bucket already seen is left untouched.
     *
     * @param  list<string>  $order
     * @param  array<string, int>  $eventsByBucket
     */
    private static function registerBucket(array &$order, array &$eventsByBucket, string $bucketKey): void
    {
        if (isset($eventsByBucket[$bucketKey])) {
            return;
        }

        $order[] = $bucketKey;
        $eventsByBucket[$bucketKey] = 0;
    }

    /**
     * A normal event row, annotated as voided when a void-edge targets it.
     *
     * @param  array<string, mixed>  $payload
     * @param  array{void_type: string, reason: string}|null  $annotation
     * @return array<string, mixed>
     */
    private static function eventRow(array $payload, ?array $annotation): array
    {
        $marker = null;
        $markerColor = null;

        if ($annotation !== null) {
            $marker = 'Voided · '.self::voidTypeLabel($annotation['void_type'])
                .self::reasonSuffix($annotation['reason']);
            $markerColor = 'warning';
        }

        $type = self::stringField($payload, 'type') ?? 'unknown';

        return [
            'event_id' => self::shortId(self::stringField($payload, 'id')),
            'type' => $type,
            'label' => self::label($type),
            'summary' => self::summarize($payload, $type),
            'timestamp' => self::intField($payload, 'timestamp'),
            'marker' => $marker,
            'marker_color' => $markerColor,
            'payload' => self::encodePayload($payload),
        ];
    }

    /**
     * A void-edge marker row. A void-edge whose target is absent from the window is
     * the missing/nulled-predecessor marker — flagged and coloured danger.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private static function voidRow(array $payload, bool $targetPresent): array
    {
        $voidType = self::stringField($payload, 'void_type') ?? '';
        $reason = self::stringField($payload, 'reason') ?? '';
        $target = self::shortId(self::stringField($payload, 'target_event_id'));

        $summary = $targetPresent
            ? 'Voids '.$target
            : 'Voids missing predecessor '.$target;

        $marker = self::voidTypeLabel($voidType).self::reasonSuffix($reason)
            .($targetPresent ? '' : ' · predecessor missing from window');

        return [
            'event_id' => self::shortId(self::stringField($payload, 'id')),
            'type' => self::VOID_EDGE_TYPE,
            'label' => 'Void edge',
            'summary' => $summary,
            'timestamp' => self::intField($payload, 'timestamp'),
            'marker' => $marker,
            'marker_color' => $targetPresent ? 'warning' : 'danger',
            'payload' => self::encodePayload($payload),
        ];
    }

    /**
     * A one-line, ciphertext-safe summary built only from structural fields (class
     * names, indices, counts, lengths) — never raw payload text, which may be
     * capture-redacted or, defensively, still sealed.
     *
     * @param  array<string, mixed>  $payload
     */
    private static function summarize(array $payload, string $type): string
    {
        return match ($type) {
            'swarm_stream_start' => trim('Stream started · '.(self::stringField($payload, 'swarm_class') ?? '')
                .self::parenthesize(self::stringField($payload, 'topology'))),
            'swarm_stream_end' => 'Stream ended',
            'swarm_stream_error' => 'Stream error',
            'swarm_node_opened' => 'Node opened'.self::parenthesize(self::stringField($payload, 'role')),
            'swarm_node_children_decided' => 'Children decided ('.self::childCount($payload).')',
            'swarm_node_closed' => 'Node closed',
            'swarm_step_start' => 'Step '.self::intField($payload, 'step_index').' started'
                .self::forAgent($payload),
            'swarm_step_end' => 'Step '.self::intField($payload, 'step_index').' ended'
                .self::forAgent($payload).self::durationSuffix($payload),
            'swarm_text_delta' => 'Text delta ('.self::deltaLength($payload).' chars)',
            'swarm_text_end' => 'Text ended',
            'swarm_reasoning_delta' => 'Reasoning delta ('.self::deltaLength($payload).' chars)',
            'swarm_reasoning_end' => 'Reasoning ended',
            'swarm_tool_call' => 'Tool call'.self::parenthesize(self::toolName($payload)),
            'swarm_tool_result' => 'Tool result'.self::parenthesize(self::toolName($payload)),
            default => self::label($type),
        };
    }

    /**
     * The human label for an event type (its render-friendly heading).
     */
    private static function label(string $type): string
    {
        return match ($type) {
            'swarm_stream_start' => 'Stream start',
            'swarm_stream_end' => 'Stream end',
            'swarm_stream_error' => 'Stream error',
            'swarm_node_opened' => 'Node opened',
            'swarm_node_children_decided' => 'Children decided',
            'swarm_node_closed' => 'Node closed',
            'swarm_step_start' => 'Step start',
            'swarm_step_end' => 'Step end',
            'swarm_text_delta' => 'Text delta',
            'swarm_text_end' => 'Text end',
            'swarm_reasoning_delta' => 'Reasoning delta',
            'swarm_reasoning_end' => 'Reasoning end',
            'swarm_tool_call' => 'Tool call',
            'swarm_tool_result' => 'Tool result',
            self::VOID_EDGE_TYPE => 'Void edge',
            default => $type,
        };
    }

    /**
     * The past-tense label for a void-edge type (#282), degrading unknown values
     * to a readable form rather than the raw enum string.
     */
    private static function voidTypeLabel(string $voidType): string
    {
        return match ($voidType) {
            'supersedes' => 'superseded',
            'replaces' => 'replaced',
            'abandons' => 'abandoned',
            'rolled_up' => 'rolled up',
            'node_reexecuted' => 're-executed',
            '' => 'voided',
            default => str_replace('_', ' ', $voidType),
        };
    }

    /**
     * Pretty-print the payload for the timeline's code block, with every string
     * leaf routed through {@see DisplayField} so a still-sealed `sw0:` value is
     * masked rather than rendered (defense in depth).
     *
     * @param  array<string, mixed>  $payload
     */
    private static function encodePayload(array $payload): string
    {
        $scrubbed = self::scrub($payload);

        return (string) json_encode(
            $scrubbed,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
    }

    /**
     * Recursively mask any string leaf that still looks like `sw0:` ciphertext,
     * reusing the single companion leak rule in {@see DisplayField}.
     */
    private static function scrub(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_map(static fn (mixed $item): mixed => self::scrub($item), $value);
        }

        if (is_string($value)) {
            $field = DisplayField::fromRow(['v' => $value], 'v');

            return $field->isAvailable() ? $value : self::UNAVAILABLE;
        }

        return $value;
    }

    /**
     * The bucket key for a payload: its `node_id`, or the top-level sentinel.
     *
     * @param  array<string, mixed>  $payload
     */
    private static function nodeKey(array $payload): string
    {
        return self::stringField($payload, 'node_id') ?? self::TOP_LEVEL_KEY;
    }

    private static function shortId(?string $id): string
    {
        if ($id === null || $id === '') {
            return '—';
        }

        return strlen($id) > 8 ? substr($id, 0, 8).'…' : $id;
    }

    private static function reasonSuffix(string $reason): string
    {
        return $reason === '' ? '' : ' — '.$reason;
    }

    private static function parenthesize(?string $value): string
    {
        return ($value === null || $value === '') ? '' : ' ('.$value.')';
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function forAgent(array $payload): string
    {
        $agent = self::stringField($payload, 'agent_class');

        return $agent === null ? '' : ' · '.$agent;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function durationSuffix(array $payload): string
    {
        $duration = $payload['duration_ms'] ?? null;

        return is_int($duration) ? ' ('.$duration.'ms)' : '';
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function deltaLength(array $payload): int
    {
        $delta = $payload['delta'] ?? null;

        return is_string($delta) ? strlen($delta) : 0;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function childCount(array $payload): int
    {
        $children = $payload['child_node_ids'] ?? null;

        return is_array($children) ? count($children) : 0;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function toolName(array $payload): ?string
    {
        $toolCall = $payload['tool_call'] ?? null;

        if (! is_array($toolCall)) {
            return null;
        }

        return is_string($toolCall['name'] ?? null) ? $toolCall['name'] : null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function stringField(array $payload, string $key): ?string
    {
        return is_string($payload[$key] ?? null) ? $payload[$key] : null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function intField(array $payload, string $key): ?int
    {
        return is_int($payload[$key] ?? null) ? $payload[$key] : null;
    }
}
