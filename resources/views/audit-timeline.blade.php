@php
    /** @var array<string, mixed> $audit */
    $audit = $audit ?? (isset($getState) ? $getState() : []);
    $records = is_array($audit['records'] ?? null) ? $audit['records'] : [];
    $notes = is_array($audit['notes'] ?? null) ? array_values(array_filter($audit['notes'], 'is_string')) : [];

    // A dot colour by evidence family: failures red, run lifecycle violet, steps
    // blue, everything else amber.
    $dot = static fn (string $c): string => match (true) {
        str_contains($c, 'fail') || str_contains($c, 'error') || str_contains($c, 'dead') => '#dc2626',
        str_starts_with($c, 'run.') => '#7c5cff',
        str_starts_with($c, 'step.') => '#2563eb',
        default => '#d97706',
    };
    $fmt = static function (?string $t): array {
        if ($t === null || $t === '') {
            return ['—', ''];
        }
        try {
            $d = \Illuminate\Support\Carbon::parse($t);

            return [$d->format('H:i:s'), $d->format('M j')];
        } catch (\Throwable) {
            return [$t, ''];
        }
    };
@endphp

<div class="swarm-audit">
    @if ($records === [])
        <div class="swarm-audit__empty">{{ $notes[0] ?? 'No audit evidence recorded for this run.' }}</div>
    @else
        <ol class="swarm-audit__list">
            @foreach ($records as $r)
                @php([$time, $date] = $fmt($r['occurred_at'] ?? null))
                <li class="swarm-audit__row">
                    <span class="swarm-audit__dot" style="--dot: {{ $dot((string) ($r['category'] ?? '')) }}"></span>
                    <span class="swarm-audit__cat">{{ $r['category'] ?? 'unknown' }}</span>
                    <span class="swarm-audit__time">{{ $time }}<span class="swarm-audit__date">{{ $date !== '' ? ' · '.$date : '' }}</span></span>
                </li>
            @endforeach
        </ol>
        @if (! empty($audit['truncated']))
            <div class="swarm-audit__note">Trail truncated at the read limit.</div>
        @endif
    @endif
</div>

<style>
    .swarm-audit__empty { color: rgb(100 116 139); font-size: .8125rem; padding: .5rem .25rem; }
    .swarm-audit__list { list-style: none; margin: 0; padding: 0; position: relative; }
    .swarm-audit__list::before { content: ''; position: absolute; left: 6px; top: 12px; bottom: 12px; width: 1px; background: rgb(226 232 240); }
    .swarm-audit__row { display: flex; align-items: center; gap: .7rem; padding: .28rem 0; position: relative; }
    .swarm-audit__dot { width: 11px; height: 11px; border-radius: 99px; background: var(--dot); box-shadow: 0 0 0 3px rgb(255 255 255); flex: 0 0 auto; z-index: 1; }
    .swarm-audit__cat { font: 500 12.5px ui-monospace, monospace; color: rgb(30 41 59); flex: 1; min-width: 0; }
    .swarm-audit__time { font: 500 11.5px ui-sans-serif, system-ui, sans-serif; color: rgb(100 116 139); font-variant-numeric: tabular-nums; white-space: nowrap; }
    .swarm-audit__date { color: rgb(148 163 184); }
    .swarm-audit__note { color: rgb(148 163 184); font-size: .75rem; margin-top: .5rem; padding-left: 1.4rem; }
    .dark .swarm-audit__list::before { background: rgb(51 65 85); }
    .dark .swarm-audit__dot { box-shadow: 0 0 0 3px rgb(30 41 59); }
    .dark .swarm-audit__cat { color: rgb(226 232 240); }
    .dark .swarm-audit__empty, .dark .swarm-audit__time { color: rgb(148 163 184); }
</style>
