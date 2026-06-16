<x-filament-panels::page>
    <div class="mb-4">
        <label class="text-sm font-medium text-gray-700 dark:text-gray-300">
            {{ __('delivery_company.reports.filters.period') }}
        </label>
        <select
            wire:model.live="periodDays"
            class="mt-1 block rounded-lg border-gray-300 text-sm shadow-sm dark:border-gray-700 dark:bg-gray-900"
        >
            @foreach ($this->periodOptions() as $value => $label)
                <option value="{{ $value }}">{{ $label }}</option>
            @endforeach
        </select>
    </div>

    @php
        $financial = $report['financial'] ?? [];
        $driverAvailability = $report['driverAvailability'] ?? [];
        $statusCounts = $report['statusCounts'] ?? [];
        $completedPerDay = $report['completedPerDay'] ?? [];
        $maxCompleted = max(1, (int) collect($completedPerDay)->max());
    @endphp

    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
        <x-filament::section>
            <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('delivery_company.reports.cards.due_balance') }}</p>
            <p class="mt-1 text-2xl font-semibold">
                {{ number_format((float) ($financial['currentBalance'] ?? 0), 2) }}
                {{ $financial['currency'] ?? '' }}
            </p>
        </x-filament::section>

        <x-filament::section>
            <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('delivery_company.reports.cards.fees_period') }}</p>
            <p class="mt-1 text-2xl font-semibold">
                {{ number_format((float) ($financial['feesInPeriod'] ?? 0), 2) }}
                {{ $financial['currency'] ?? '' }}
            </p>
        </x-filament::section>

        <x-filament::section>
            <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('delivery_company.reports.cards.open_disputes') }}</p>
            <p class="mt-1 text-2xl font-semibold">{{ (int) ($report['openDisputesCount'] ?? 0) }}</p>
        </x-filament::section>

        <x-filament::section>
            <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('delivery_company.reports.cards.total_disputes') }}</p>
            <p class="mt-1 text-2xl font-semibold">{{ (int) ($report['disputesCount'] ?? 0) }}</p>
        </x-filament::section>
    </div>

    <div class="mt-6 grid gap-6 lg:grid-cols-2">
        <x-filament::section :heading="__('delivery_company.reports.sections.order_status')">
            @if ($statusCounts === [])
                <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('delivery_company.reports.empty') }}</p>
            @else
                <div class="space-y-2">
                    @foreach ($statusCounts as $status => $count)
                        <div class="flex items-center justify-between text-sm">
                            <span>{{ $this->statusLabel((string) $status) }}</span>
                            <span class="font-medium">{{ $count }}</span>
                        </div>
                    @endforeach
                </div>
            @endif
        </x-filament::section>

        <x-filament::section :heading="__('delivery_company.reports.sections.driver_availability')">
            <div class="space-y-2 text-sm">
                <div class="flex items-center justify-between">
                    <span>{{ __('delivery_company.drivers.enums.availability.available') }}</span>
                    <span class="font-medium">{{ (int) ($driverAvailability['available'] ?? 0) }}</span>
                </div>
                <div class="flex items-center justify-between">
                    <span>{{ __('delivery_company.drivers.enums.availability.busy') }}</span>
                    <span class="font-medium">{{ (int) ($driverAvailability['busy'] ?? 0) }}</span>
                </div>
                <div class="flex items-center justify-between">
                    <span>{{ __('delivery_company.drivers.enums.availability.offline') }}</span>
                    <span class="font-medium">{{ (int) ($driverAvailability['offline'] ?? 0) }}</span>
                </div>
                <div class="flex items-center justify-between">
                    <span>{{ __('delivery_company.reports.fields.suspended_drivers') }}</span>
                    <span class="font-medium">{{ (int) ($driverAvailability['suspended'] ?? 0) }}</span>
                </div>
                <div class="flex items-center justify-between border-t border-gray-100 pt-2 dark:border-gray-800">
                    <span>{{ __('delivery_company.reports.fields.total_drivers') }}</span>
                    <span class="font-medium">{{ (int) ($driverAvailability['total'] ?? 0) }}</span>
                </div>
            </div>
        </x-filament::section>
    </div>

    <x-filament::section class="mt-6" :heading="__('delivery_company.reports.sections.completed_chart')">
        @if ($completedPerDay === [])
            <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('delivery_company.reports.empty_chart') }}</p>
        @else
            <div class="space-y-3">
                @foreach ($completedPerDay as $day => $count)
                    <div>
                        <div class="mb-1 flex items-center justify-between text-xs text-gray-500 dark:text-gray-400">
                            <span>{{ $day }}</span>
                            <span>{{ $count }}</span>
                        </div>
                        <div class="h-2 rounded-full bg-gray-100 dark:bg-gray-800">
                            <div
                                class="h-2 rounded-full bg-primary-500"
                                style="width: {{ round(((int) $count / $maxCompleted) * 100) }}%"
                            ></div>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </x-filament::section>
</x-filament-panels::page>
