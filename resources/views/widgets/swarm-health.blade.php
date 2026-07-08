{{-- Compact Swarm health widget: the swarm:health durable + audit readiness
     verdict at a glance. Payload-free and control-free. --}}
<x-filament-widgets::widget class="fi-swarm-health-widget">
    <x-filament::section>
        <x-slot name="heading">Swarm health</x-slot>

        <x-slot name="afterHeader">
            <x-filament::badge :color="$report->status->getColor()" class="fi-swarm-health-overall">
                {{ $report->status->label() }}
            </x-filament::badge>
        </x-slot>

        <div class="fi-swarm-health-checks grid gap-3 sm:grid-cols-2">
            @foreach ($report->checks as $check)
                <div
                    wire:key="swarm-health-widget-{{ $check->key }}"
                    class="fi-swarm-health-check flex items-center justify-between gap-3 rounded-lg bg-gray-50 px-3 py-2 dark:bg-white/5"
                    data-check="{{ $check->key }}"
                    data-status="{{ $check->status->value }}"
                >
                    <span class="truncate text-sm font-medium text-gray-950 dark:text-white">
                        {{ $check->label }}
                    </span>

                    <x-filament::badge :color="$check->status->getColor()">
                        {{ $check->status->label() }}
                    </x-filament::badge>
                </div>
            @endforeach
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
