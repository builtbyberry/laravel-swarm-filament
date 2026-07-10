@php
    /** @var array<int, array<string, mixed>> $artifacts */
    $artifacts = $artifacts ?? (isset($getState) ? $getState() : []);
    $artifacts = is_array($artifacts) ? array_values(array_filter($artifacts, 'is_array')) : [];
@endphp

<div class="swarm-artifacts">
    @if ($artifacts === [])
        <div class="swarm-artifacts__empty">This run produced no artifacts.</div>
    @else
        <ul class="swarm-artifacts__list">
            @foreach ($artifacts as $artifact)
                <li class="swarm-artifacts__item">
                    <div class="swarm-artifacts__head">
                        <span class="swarm-artifacts__name">{{ $artifact['name'] ?? '(unnamed)' }}</span>
                        @if (! empty($artifact['step_agent_class']))
                            <span class="swarm-artifacts__agent">{{ class_basename((string) $artifact['step_agent_class']) }}</span>
                        @endif
                    </div>
                    {{-- Content is pre-degraded by RunDisplayPresenter (sealed leaves masked); rendered as text. --}}
                    <pre class="swarm-artifacts__content">{{ $artifact['content'] ?? 'none' }}</pre>
                </li>
            @endforeach
        </ul>
    @endif
</div>

<style>
    .swarm-artifacts__empty { color: rgb(100 116 139); font-size: .8125rem; padding: .5rem .25rem; }
    .swarm-artifacts__list { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: .6rem; }
    .swarm-artifacts__item { border: 1px solid rgb(226 232 240); border-radius: .5rem; padding: .6rem .75rem; }
    .swarm-artifacts__head { display: flex; align-items: baseline; gap: .6rem; margin-bottom: .35rem; }
    .swarm-artifacts__name { font: 600 13px ui-sans-serif, system-ui, sans-serif; color: rgb(30 41 59); }
    .swarm-artifacts__agent { font: 500 11px ui-monospace, monospace; color: rgb(100 116 139); }
    .swarm-artifacts__content { margin: 0; font: 12px ui-monospace, monospace; color: rgb(51 65 85); white-space: pre-wrap; word-break: break-word; max-height: 18rem; overflow: auto; }
    .dark .swarm-artifacts__item { border-color: rgb(51 65 85); }
    .dark .swarm-artifacts__name { color: rgb(226 232 240); }
    .dark .swarm-artifacts__agent, .dark .swarm-artifacts__empty { color: rgb(148 163 184); }
    .dark .swarm-artifacts__content { color: rgb(203 213 225); }
</style>
