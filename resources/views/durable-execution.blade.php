@php
    /** @var array<string, mixed> $durable */
    $durable = $durable ?? (isset($getState) ? $getState() : []);
    $durable = is_array($durable) ? $durable : [];

    $waits = is_array($durable['waits'] ?? null) ? $durable['waits'] : [];
    $signals = is_array($durable['signals'] ?? null) ? $durable['signals'] : [];
    $progress = is_array($durable['progress'] ?? null) ? $durable['progress'] : [];
    $timeout = is_array($durable['timeout'] ?? null) ? $durable['timeout'] : [];

    // A rendered payload is 'none' when absent — treat that as nothing to show.
    $shown = static fn (?string $value): bool => $value !== null && $value !== '' && $value !== 'none';
@endphp

<div class="swarm-durable">
    {{-- Run-level durable status: framework-set structural scalars only. --}}
    <dl class="swarm-durable__status">
        <div class="swarm-durable__stat">
            <dt>Status</dt>
            <dd>{{ $durable['status'] ?? '—' }}</dd>
        </div>
        <div class="swarm-durable__stat">
            <dt>Retry attempt</dt>
            <dd>{{ $durable['retry_attempt'] ?? 0 }}</dd>
        </div>
        <div class="swarm-durable__stat">
            <dt>Recovery count</dt>
            <dd>{{ $durable['recovery_count'] ?? 0 }}</dd>
        </div>
        <div class="swarm-durable__stat">
            <dt>Timeout</dt>
            <dd>
                @if (! empty($timeout['timed_out']))
                    Timed out{{ $shown($timeout['timed_out_at'] ?? null) ? ' at '.$timeout['timed_out_at'] : '' }}
                @elseif ($shown($timeout['timeout_at'] ?? null))
                    Due {{ $timeout['timeout_at'] }}
                @else
                    —
                @endif
            </dd>
        </div>
    </dl>

    {{-- Waits: what the run is blocked on. --}}
    <section class="swarm-durable__group">
        <h4 class="swarm-durable__title">Waits</h4>
        @if ($waits === [])
            <div class="swarm-durable__empty">This run is not blocked on any waits.</div>
        @else
            <ul class="swarm-durable__list">
                @foreach ($waits as $wait)
                    <li class="swarm-durable__item">
                        <div class="swarm-durable__head">
                            <span class="swarm-durable__name">{{ $wait['name'] ?? '(unnamed)' }}</span>
                            @if (! empty($wait['status']))
                                <span class="swarm-durable__badge">{{ $wait['status'] }}</span>
                            @endif
                            @if ($shown($wait['timeout_at'] ?? null))
                                <span class="swarm-durable__meta">times out {{ $wait['timeout_at'] }}</span>
                            @endif
                        </div>
                        {{-- reason / outcome are sealed-routed by DurableExecutionPresenter. --}}
                        @if ($shown($wait['reason'] ?? null))
                            <div class="swarm-durable__reason">{{ $wait['reason'] }}</div>
                        @endif
                        @if ($shown($wait['outcome'] ?? null))
                            <pre class="swarm-durable__payload">{{ $wait['outcome'] }}</pre>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

    {{-- Signals: what the run received. --}}
    <section class="swarm-durable__group">
        <h4 class="swarm-durable__title">Signals</h4>
        @if ($signals === [])
            <div class="swarm-durable__empty">This run received no signals.</div>
        @else
            <ul class="swarm-durable__list">
                @foreach ($signals as $signal)
                    <li class="swarm-durable__item">
                        <div class="swarm-durable__head">
                            <span class="swarm-durable__name">{{ $signal['name'] ?? '(unnamed)' }}</span>
                            @if (! empty($signal['status']))
                                <span class="swarm-durable__badge">{{ $signal['status'] }}</span>
                            @endif
                            @if ($shown($signal['consumed_at'] ?? null))
                                <span class="swarm-durable__meta">consumed {{ $signal['consumed_at'] }}</span>
                            @endif
                        </div>
                        {{-- payload is sealed-routed by DurableExecutionPresenter. --}}
                        @if ($shown($signal['payload'] ?? null))
                            <pre class="swarm-durable__payload">{{ $signal['payload'] }}</pre>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

    {{-- Progress: markers the run recorded. --}}
    <section class="swarm-durable__group">
        <h4 class="swarm-durable__title">Progress</h4>
        @if ($progress === [])
            <div class="swarm-durable__empty">This run recorded no progress markers.</div>
        @else
            <ul class="swarm-durable__list">
                @foreach ($progress as $marker)
                    <li class="swarm-durable__item">
                        <div class="swarm-durable__head">
                            <span class="swarm-durable__name">
                                {{ ! empty($marker['agent_class']) ? class_basename((string) $marker['agent_class']) : ($marker['branch_id'] ?? 'run') }}
                            </span>
                            @if (($marker['step_index'] ?? null) !== null)
                                <span class="swarm-durable__badge">step {{ $marker['step_index'] }}</span>
                            @endif
                            @if ($shown($marker['last_progress_at'] ?? null))
                                <span class="swarm-durable__meta">{{ $marker['last_progress_at'] }}</span>
                            @endif
                        </div>
                        {{-- detail is sealed-routed by DurableExecutionPresenter. --}}
                        @if ($shown($marker['detail'] ?? null))
                            <pre class="swarm-durable__payload">{{ $marker['detail'] }}</pre>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    </section>
</div>

<style>
    .swarm-durable { display: flex; flex-direction: column; gap: 1rem; }
    .swarm-durable__status { display: grid; grid-template-columns: repeat(auto-fit, minmax(8rem, 1fr)); gap: .6rem; margin: 0; }
    .swarm-durable__stat { border: 1px solid rgb(226 232 240); border-radius: .5rem; padding: .5rem .6rem; }
    .swarm-durable__stat dt { font: 500 11px ui-sans-serif, system-ui, sans-serif; color: rgb(100 116 139); margin: 0 0 .2rem; }
    .swarm-durable__stat dd { font: 600 14px ui-sans-serif, system-ui, sans-serif; color: rgb(30 41 59); margin: 0; }
    .swarm-durable__group { display: flex; flex-direction: column; gap: .4rem; }
    .swarm-durable__title { font: 600 12px ui-sans-serif, system-ui, sans-serif; color: rgb(71 85 105); text-transform: uppercase; letter-spacing: .04em; margin: 0; }
    .swarm-durable__empty { color: rgb(100 116 139); font-size: .8125rem; padding: .35rem .25rem; }
    .swarm-durable__list { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: .5rem; }
    .swarm-durable__item { border: 1px solid rgb(226 232 240); border-radius: .5rem; padding: .55rem .7rem; }
    .swarm-durable__head { display: flex; align-items: baseline; flex-wrap: wrap; gap: .5rem; }
    .swarm-durable__name { font: 600 13px ui-sans-serif, system-ui, sans-serif; color: rgb(30 41 59); }
    .swarm-durable__badge { font: 500 11px ui-monospace, monospace; color: rgb(71 85 105); background: rgb(241 245 249); border-radius: .3rem; padding: .05rem .35rem; }
    .swarm-durable__meta { font: 500 11px ui-monospace, monospace; color: rgb(100 116 139); }
    .swarm-durable__reason { font: 13px ui-sans-serif, system-ui, sans-serif; color: rgb(51 65 85); margin-top: .3rem; }
    .swarm-durable__payload { margin: .3rem 0 0; font: 12px ui-monospace, monospace; color: rgb(51 65 85); white-space: pre-wrap; word-break: break-word; max-height: 14rem; overflow: auto; }
    .dark .swarm-durable__stat, .dark .swarm-durable__item { border-color: rgb(51 65 85); }
    .dark .swarm-durable__stat dd, .dark .swarm-durable__name { color: rgb(226 232 240); }
    .dark .swarm-durable__stat dt, .dark .swarm-durable__title, .dark .swarm-durable__meta, .dark .swarm-durable__empty { color: rgb(148 163 184); }
    .dark .swarm-durable__badge { color: rgb(203 213 225); background: rgb(30 41 59); }
    .dark .swarm-durable__reason, .dark .swarm-durable__payload { color: rgb(203 213 225); }
</style>
