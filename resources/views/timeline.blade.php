@php
    /** @var array<string, mixed> $timeline */
    $timeline = $timeline ?? (isset($getState) ? $getState() : ['nodes' => []]);
    $nodes = is_array($timeline['nodes'] ?? null) ? $timeline['nodes'] : [];
    $chipColor = static fn (?string $c): string => match ($c) {
        'danger' => '#dc2626',
        'warning' => '#d97706',
        'success' => '#16a34a',
        default => '#64748b',
    };
@endphp

<div class="swarm-tl">
    @if ($nodes === [])
        <div class="swarm-tl__empty">No streamed events for this run.</div>
    @else
        @foreach ($nodes as $node)
            <div class="swarm-tl__lane">
                <div class="swarm-tl__lane-label" title="{{ $node['node_id'] ?? '' }}">
                    {{ \Illuminate\Support\Str::limit($node['label'] ?? $node['node_id'] ?? 'node', 22) }}
                </div>
                <div class="swarm-tl__track">
                    @foreach (($node['events'] ?? []) as $event)
                        @php($c = $chipColor($event['marker_color'] ?? null))
                        <div class="swarm-tl__chip @if(!empty($event['marker'])) swarm-tl__chip--marker @endif"
                             style="--chip: {{ $c }}"
                             title="{{ $event['label'] ?? $event['type'] ?? '' }}@if(!empty($event['summary'])) — {{ $event['summary'] }}@endif">
                            <span class="swarm-tl__dot"></span>
                            <span class="swarm-tl__chip-label">{{ \Illuminate\Support\Str::limit($event['label'] ?? $event['type'] ?? 'event', 18) }}</span>
                        </div>
                        @if (! $loop->last)
                            <span class="swarm-tl__link"></span>
                        @endif
                    @endforeach
                </div>
            </div>
        @endforeach
    @endif
</div>

<style>
    .swarm-tl { display: flex; flex-direction: column; gap: .5rem; }
    .swarm-tl__lane { display: flex; align-items: center; gap: .75rem; }
    .swarm-tl__lane-label { flex: 0 0 9rem; font: 600 12px ui-sans-serif, system-ui, sans-serif; color: rgb(15 23 42); text-align: right; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .swarm-tl__track { display: flex; align-items: center; gap: 0; overflow-x: auto; padding: .25rem 0; flex: 1 1 auto; }
    .swarm-tl__chip { display: inline-flex; align-items: center; gap: .4rem; padding: .25rem .55rem; border-radius: 9999px; border: 1px solid rgb(226 232 240); background: rgb(255 255 255); white-space: nowrap; font: 500 11px ui-sans-serif, system-ui, sans-serif; color: rgb(15 23 42); }
    .swarm-tl__chip--marker { border-color: var(--chip); border-style: dashed; }
    .swarm-tl__dot { width: 7px; height: 7px; border-radius: 9999px; background: var(--chip); flex: 0 0 auto; }
    .swarm-tl__link { width: 14px; height: 1px; background: rgb(203 213 225); flex: 0 0 auto; }
    .swarm-tl__empty { color: rgb(100 116 139); font-size: .875rem; padding: 1rem 0; }
    .dark .swarm-tl__lane-label { color: rgb(241 245 249); }
    .dark .swarm-tl__chip { background: rgb(30 41 59); border-color: rgb(51 65 85); color: rgb(241 245 249); }
    .dark .swarm-tl__link { background: rgb(51 65 85); }
</style>
