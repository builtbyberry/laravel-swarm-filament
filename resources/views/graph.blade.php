@php
    /** @var array<string, mixed> $graph */
    $graph = $graph ?? (isset($getState) ? $getState() : ['empty' => true]);
    $empty = ($graph['empty'] ?? true) || empty($graph['nodes'] ?? []);
    $statusFill = static fn (?string $s): string => match ($s) {
        'completed', 'succeeded', 'done' => '#16a34a',
        'failed', 'errored', 'dead_letter' => '#dc2626',
        'running', 'in_progress', 'waiting', 'paused' => '#d97706',
        default => '#64748b',
    };
@endphp

<div class="swarm-graph" x-data="{ hover: null }">
    @if ($empty)
        <div class="swarm-graph__empty">No workflow to display for this run.</div>
    @else
        <div class="swarm-graph__scroll">
            <svg viewBox="0 0 {{ $graph['width'] }} {{ $graph['height'] }}"
                 width="{{ $graph['width'] }}" height="{{ $graph['height'] }}"
                 class="swarm-graph__svg" role="img" aria-label="Workflow graph">
                <defs>
                    <marker id="swarm-arrow" markerWidth="8" markerHeight="8" refX="7" refY="4" orient="auto">
                        <path d="M0,0 L8,4 L0,8 Z" fill="currentColor" opacity="0.45" />
                    </marker>
                </defs>

                @foreach ($graph['edges'] as $edge)
                    <path d="{{ $edge['d'] }}" fill="none" stroke="currentColor"
                          class="swarm-graph__edge"
                          x-bind:stroke-opacity="hover && hover !== '{{ $edge['from'] }}' && hover !== '{{ $edge['to'] }}' ? 0.12 : 0.4"
                          marker-end="url(#swarm-arrow)" />
                @endforeach

                @foreach ($graph['nodes'] as $node)
                    @php($fill = $statusFill($node['status'] ?? null))
                    <g class="swarm-graph__node"
                       x-on:mouseenter="hover = '{{ $node['id'] }}'" x-on:mouseleave="hover = null"
                       x-bind:opacity="hover && hover !== '{{ $node['id'] }}' ? 0.5 : 1">
                        <title>{{ $node['label'] }}@if (!empty($node['sublabel'])) — {{ $node['sublabel'] }}@endif @if (!empty($node['status'])) ({{ $node['status'] }})@endif</title>
                        <rect x="{{ $node['x'] }}" y="{{ $node['y'] }}" width="{{ $node['w'] }}" height="{{ $node['h'] }}"
                              rx="10" class="swarm-graph__box" />
                        <rect x="{{ $node['x'] }}" y="{{ $node['y'] }}" width="5" height="{{ $node['h'] }}"
                              rx="2" fill="{{ $fill }}" />
                        <circle cx="{{ $node['x'] + $node['w'] - 16 }}" cy="{{ $node['y'] + 18 }}" r="4" fill="{{ $fill }}" />
                        <text x="{{ $node['x'] + 16 }}" y="{{ $node['y'] + 26 }}" class="swarm-graph__label">{{ \Illuminate\Support\Str::limit($node['label'], 22) }}</text>
                        @if (!empty($node['sublabel']))
                            <text x="{{ $node['x'] + 16 }}" y="{{ $node['y'] + 44 }}" class="swarm-graph__sublabel">{{ \Illuminate\Support\Str::limit($node['sublabel'], 26) }}</text>
                        @endif
                    </g>
                @endforeach
            </svg>
        </div>
    @endif
</div>

<style>
    .swarm-graph__scroll { overflow-x: auto; padding: 0.25rem 0; }
    .swarm-graph__svg { max-width: 100%; height: auto; color: rgb(100 116 139); }
    .swarm-graph__edge { transition: stroke-opacity .15s ease; }
    .swarm-graph__node { cursor: default; transition: opacity .15s ease; }
    .swarm-graph__box { fill: rgb(255 255 255); stroke: rgb(226 232 240); stroke-width: 1.5; }
    .swarm-graph__label { fill: rgb(15 23 42); font: 600 13px ui-sans-serif, system-ui, sans-serif; }
    .swarm-graph__sublabel { fill: rgb(100 116 139); font: 400 11px ui-sans-serif, system-ui, sans-serif; }
    .swarm-graph__empty { color: rgb(100 116 139); font-size: .875rem; padding: 1rem 0; }
    .dark .swarm-graph__box { fill: rgb(30 41 59); stroke: rgb(51 65 85); }
    .dark .swarm-graph__label { fill: rgb(241 245 249); }
    .dark .swarm-graph__svg { color: rgb(148 163 184); }
</style>
