{{-- The read-only Swarm health dashboard: swarm:health readiness (durable + audit
     persistence) as a pass/fail/degraded view. Payload-free and control-free. --}}
<x-filament-panels::page>
    <x-filament::section>
        <x-slot name="heading">Persistence readiness</x-slot>
        <x-slot name="description">
            Durable and audit persistence reachability, read through Swarm's public
            read-only contracts. Pass/fail only — no run payloads, no operator actions.
        </x-slot>

        <x-slot name="afterHeader">
            <x-filament::badge :color="$report->status->getColor()" class="fi-swarm-health-overall">
                {{ $report->status->label() }}
            </x-filament::badge>
        </x-slot>

        <ul role="list" class="fi-swarm-health-checks divide-y divide-gray-100 dark:divide-white/10">
            @foreach ($report->checks as $check)
                <li
                    wire:key="swarm-health-{{ $check->key }}"
                    class="fi-swarm-health-check flex items-start justify-between gap-3 py-3"
                    data-check="{{ $check->key }}"
                    data-status="{{ $check->status->value }}"
                >
                    <div class="min-w-0">
                        <p class="text-sm font-medium text-gray-950 dark:text-white">
                            {{ $check->label }}
                        </p>
                        <p class="mt-0.5 text-sm text-gray-500 dark:text-gray-400">
                            {{ $check->summary }}
                        </p>
                    </div>

                    <x-filament::badge :color="$check->status->getColor()">
                        {{ $check->status->label() }}
                    </x-filament::badge>
                </li>
            @endforeach
        </ul>
    </x-filament::section>
</x-filament-panels::page>
