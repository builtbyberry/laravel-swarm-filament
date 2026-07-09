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
    // Per-node input/output for the click-through detail panel.
    $details = [];
    foreach (($graph['nodes'] ?? []) as $n) {
        $details[$n['id']] = [
            'label' => $n['label'] ?? $n['id'],
            'sublabel' => $n['sublabel'] ?? null,
            'input' => $n['detail_input'] ?? null,
            'output' => $n['detail_output'] ?? null,
            'wrote' => $n['detail_wrote'] ?? [],
            'memory' => $n['detail_memory'] ?? [],
            'tools' => $n['detail_tools'] ?? [],
        ];
    }
@endphp

<div class="swarm-graph" x-data="{ sel: null, details: @js($details) }">
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
                          x-bind:stroke-opacity="sel && sel !== '{{ $edge['from'] }}' && sel !== '{{ $edge['to'] }}' ? 0.12 : 0.4"
                          marker-end="url(#swarm-arrow)" />
                @endforeach

                @foreach ($graph['nodes'] as $node)
                    @php($fill = $statusFill($node['status'] ?? null))
                    <g class="swarm-graph__node" x-on:click="sel = (sel === '{{ $node['id'] }}' ? null : '{{ $node['id'] }}')"
                       x-bind:opacity="sel && sel !== '{{ $node['id'] }}' ? 0.55 : 1">
                        <rect x="{{ $node['x'] }}" y="{{ $node['y'] }}" width="{{ $node['w'] }}" height="{{ $node['h'] }}"
                              rx="12" class="swarm-graph__box"
                              x-bind:stroke="sel === '{{ $node['id'] }}' ? '{{ $fill }}' : null" />
                        <rect x="{{ $node['x'] }}" y="{{ $node['y'] }}" width="5" height="{{ $node['h'] }}" rx="2" fill="{{ $fill }}" />
                        <circle cx="{{ $node['x'] + $node['w'] - 18 }}" cy="{{ $node['y'] + 20 }}" r="4" fill="{{ $fill }}" />
                        <text x="{{ $node['x'] + 18 }}" y="{{ $node['y'] + 26 }}" class="swarm-graph__label">{{ \Illuminate\Support\Str::limit($node['label'], 24) }}</text>
                        @if (!empty($node['sublabel']))
                            <text x="{{ $node['x'] + 18 }}" y="{{ $node['y'] + 45 }}" class="swarm-graph__sublabel">{{ \Illuminate\Support\Str::limit($node['sublabel'], 28) }}</text>
                        @endif
                        @if (!empty($node['summary']))
                            <text x="{{ $node['x'] + 18 }}" y="{{ $node['y'] + 70 }}" class="swarm-graph__summary">{{ \Illuminate\Support\Str::limit($node['summary'], 32) }}</text>
                        @endif
                        @if (!empty($node['tokens']))
                            <text x="{{ $node['x'] + 18 }}" y="{{ $node['y'] + 92 }}" class="swarm-graph__meta">{{ number_format($node['tokens']) }} tokens</text>
                        @endif
                    </g>
                @endforeach
            </svg>
        </div>

        <div class="swarm-graph__detail" x-show="sel" x-cloak>
            <template x-if="sel && details[sel]">
                <div>
                    <div class="swarm-graph__detail-head"><span x-text="details[sel].label"></span> <span class="swarm-graph__detail-sub" x-text="details[sel].sublabel"></span></div>
                    <div class="swarm-graph__io"><div class="swarm-graph__io-label">Input</div><pre x-text="details[sel].input || '—'"></pre></div>
                    <div class="swarm-graph__io"><div class="swarm-graph__io-label">Output</div><pre x-text="details[sel].output || '—'"></pre></div>
                    <div class="swarm-graph__facets" x-show="details[sel].wrote.length || details[sel].tools.length || details[sel].memory.length">
                        <div class="swarm-graph__facet" x-show="details[sel].wrote.length">
                            <div class="swarm-graph__io-label">Memory written</div>
                            <div class="swarm-graph__chips"><template x-for="k in details[sel].wrote" :key="k"><span class="swarm-graph__chip swarm-graph__chip--wrote">wrote <b x-text="k"></b></span></template></div>
                        </div>
                        <div class="swarm-graph__facet" x-show="details[sel].tools.length">
                            <div class="swarm-graph__io-label">Tools called</div>
                            <div class="swarm-graph__chips"><template x-for="t in details[sel].tools" :key="t"><span class="swarm-graph__chip" x-text="t"></span></template></div>
                        </div>
                        <div class="swarm-graph__facet" x-show="details[sel].memory.length">
                            <div class="swarm-graph__io-label">Memory visible here</div>
                            <div class="swarm-graph__mem"><template x-for="e in details[sel].memory" :key="e.key + e.scope"><div class="swarm-graph__memrow"><span class="swarm-graph__memk" x-text="e.key"></span><span class="swarm-graph__memv" x-text="e.value"></span></div></template></div>
                        </div>
                    </div>
                </div>
            </template>
        </div>
        <div class="swarm-graph__hint" x-show="!sel">Click a step to see its input and output.</div>
    @endif
</div>

<style>
    [x-cloak] { display: none !important; }
    .swarm-graph__scroll { overflow-x: auto; padding: .25rem 0; }
    .swarm-graph__svg { max-width: 100%; height: auto; color: rgb(100 116 139); }
    .swarm-graph__edge { transition: stroke-opacity .15s ease; }
    .swarm-graph__node { cursor: pointer; transition: opacity .15s ease; }
    .swarm-graph__box { fill: rgb(255 255 255); stroke: rgb(226 232 240); stroke-width: 1.5; }
    .swarm-graph__label { fill: rgb(15 23 42); font: 600 14px ui-sans-serif, system-ui, sans-serif; }
    .swarm-graph__sublabel { fill: rgb(37 99 235); font: 600 11px ui-sans-serif, system-ui, sans-serif; letter-spacing: .02em; }
    .swarm-graph__summary { fill: rgb(71 85 105); font: 400 12px ui-sans-serif, system-ui, sans-serif; }
    .swarm-graph__meta { fill: rgb(100 116 139); font: 500 10px ui-sans-serif, system-ui, sans-serif; }
    .swarm-graph__empty, .swarm-graph__hint { color: rgb(100 116 139); font-size: .8125rem; padding: .75rem .25rem; }
    .swarm-graph__detail { margin-top: .5rem; border-top: 1px solid rgb(226 232 240); padding-top: .75rem; }
    .swarm-graph__detail-head { font: 600 13px ui-sans-serif, system-ui, sans-serif; color: rgb(15 23 42); margin-bottom: .5rem; }
    .swarm-graph__detail-sub { color: rgb(37 99 235); font-weight: 600; font-size: 11px; }
    .swarm-graph__io { margin-bottom: .5rem; }
    .swarm-graph__io-label { font: 600 11px ui-sans-serif, system-ui, sans-serif; color: rgb(100 116 139); text-transform: uppercase; letter-spacing: .05em; margin-bottom: .15rem; }
    .swarm-graph__io pre { white-space: pre-wrap; word-break: break-word; font: 400 12px ui-monospace, monospace; color: rgb(30 41 59); background: rgb(248 250 252); border: 1px solid rgb(226 232 240); border-radius: 8px; padding: .5rem .625rem; margin: 0; max-height: 16rem; overflow: auto; }
    .dark .swarm-graph__box { fill: rgb(30 41 59); stroke: rgb(51 65 85); }
    .dark .swarm-graph__label { fill: rgb(241 245 249); }
    .dark .swarm-graph__summary { fill: rgb(148 163 184); }
    .dark .swarm-graph__svg { color: rgb(148 163 184); }
    .dark .swarm-graph__detail, .dark .swarm-graph__detail-head { border-color: rgb(51 65 85); }
    .dark .swarm-graph__detail-head { color: rgb(241 245 249); }
    .dark .swarm-graph__io pre { color: rgb(226 232 240); background: rgb(15 23 42); border-color: rgb(51 65 85); }
    .swarm-graph__facets { display: flex; flex-wrap: wrap; gap: 22px; margin-top: 14px; padding-top: 13px; border-top: 1px solid rgb(238 241 246); }
    .swarm-graph__facet { min-width: 0; }
    .swarm-graph__chips { display: flex; gap: 6px; flex-wrap: wrap; }
    .swarm-graph__chip { font: 500 11.5px ui-monospace, monospace; padding: 3px 9px; border-radius: 99px; background: rgb(248 250 252); border: 1px solid rgb(226 232 240); color: rgb(71 85 105); }
    .swarm-graph__chip b { color: rgb(15 23 42); font-weight: 650; }
    .swarm-graph__chip--wrote { color: rgb(47 86 230); border-color: rgb(199 213 255); background: rgb(240 244 255); }
    .swarm-graph__mem { display: flex; flex-direction: column; gap: 5px; }
    .swarm-graph__memrow { display: flex; gap: 10px; font: 400 12px ui-monospace, monospace; align-items: baseline; }
    .swarm-graph__memk { color: rgb(15 23 42); font-weight: 640; min-width: 96px; }
    .swarm-graph__memv { color: rgb(90 100 115); word-break: break-word; }
    .dark .swarm-graph__facets { border-color: rgb(36 44 55); }
    .dark .swarm-graph__chip { background: rgb(20 26 34); border-color: rgb(51 65 85); color: rgb(148 163 184); }
    .dark .swarm-graph__chip b, .dark .swarm-graph__memk { color: rgb(226 232 240); }
    .dark .swarm-graph__chip--wrote { color: rgb(147 170 255); border-color: rgb(52 66 110); background: rgb(23 30 54); }
    .dark .swarm-graph__memv { color: rgb(148 163 184); }
</style>
