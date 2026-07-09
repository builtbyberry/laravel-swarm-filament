{{-- The read-only Swarm health dashboard: swarm:health readiness (durable + audit
     persistence) as a pass/fail/degraded view. Payload-free and control-free. --}}
@php
    $hex = static fn (string $s): string => match ($s) {
        'pass', 'passed' => '#16a34a',
        'fail', 'failed' => '#dc2626',
        default => '#d97706',
    };
@endphp
<x-filament-panels::page>
    <div class="swh">
        <div class="swh__head">
            <div>
                <h2 class="swh__title">Persistence readiness</h2>
                <p class="swh__sub">Can Swarm persist and recover work right now — read through the public read-only contracts. Pass/fail only, no run payloads, no operator actions.</p>
            </div>
            <x-filament::badge :color="$report->status->getColor()" size="lg">{{ $report->status->label() }}</x-filament::badge>
        </div>

        <div class="swh__cards">
            @foreach ($report->checks as $check)
                <div class="swh__card" data-check="{{ $check->key }}" data-status="{{ $check->status->value }}"
                     style="--edge: {{ $hex($check->status->value) }}">
                    <div class="swh__card-top">
                        <span class="swh__dot"></span>
                        <h3 class="swh__card-title">{{ $check->label }}</h3>
                        <x-filament::badge :color="$check->status->getColor()">{{ $check->status->label() }}</x-filament::badge>
                    </div>
                    <p class="swh__card-sum">{{ $check->summary }}</p>
                </div>
            @endforeach
        </div>
    </div>
</x-filament-panels::page>

<style>
    .swh { display: flex; flex-direction: column; gap: 1.1rem; max-width: 46rem; }
    .swh__head { display: flex; align-items: flex-start; justify-content: space-between; gap: 1rem; }
    .swh__title { margin: 0; font-size: 1.125rem; font-weight: 650; letter-spacing: -.01em; color: rgb(15 23 42); }
    .swh__sub { margin: .3rem 0 0; font-size: .875rem; line-height: 1.5; color: rgb(90 100 115); max-width: 60ch; }
    .swh__cards { display: flex; flex-direction: column; gap: .75rem; }
    .swh__card { background: rgb(255 255 255); border: 1px solid rgb(226 232 240); border-left: 4px solid var(--edge);
        border-radius: 12px; padding: 14px 18px; box-shadow: 0 1px 2px rgba(20,30,55,.05); }
    .swh__card-top { display: flex; align-items: center; gap: .6rem; }
    .swh__dot { width: 9px; height: 9px; border-radius: 99px; background: var(--edge); flex: 0 0 auto; }
    .swh__card-title { margin: 0; flex: 1; font-size: 1rem; font-weight: 640; letter-spacing: -.01em; color: rgb(15 23 42); }
    .swh__card-sum { margin: .5rem 0 0 1.5rem; font-size: .875rem; line-height: 1.5; color: rgb(90 100 115); max-width: 62ch; }
    .dark .swh__title, .dark .swh__card-title { color: rgb(241 245 249); }
    .dark .swh__sub, .dark .swh__card-sum { color: rgb(148 163 184); }
    .dark .swh__card { background: rgb(30 41 59); border-color: rgb(51 65 85); border-left-color: var(--edge); }
</style>
