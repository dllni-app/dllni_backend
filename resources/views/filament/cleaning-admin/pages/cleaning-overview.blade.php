<x-filament-hub.page-shell>
    <x-filament-hub.kpi-grid columns="md:grid-cols-5">
        @foreach ($overviewKpis as $kpi)
            <x-filament::section>
                <x-filament-hub.kpi-stat :label="$kpi['label']" :value="$kpi['value']" format-value-as-integer
                    card-padding="p-0" />
            </x-filament::section>
        @endforeach
    </x-filament-hub.kpi-grid>

    @if (count($sosAlerts) > 0)
        <x-filament::section :heading="__('cleaning_admin.overview.alerts.sos_heading')"
            class="rounded-lg border-2 border-red-500 bg-red-50 dark:bg-red-950/30">
            <div class="space-y-2">
                @foreach ($sosAlerts as $alert)
                    <x-filament-hub.alert-strip-row tone="danger">
                        <x-slot name="leading">
                            <span
                                class="font-semibold text-red-700 dark:text-red-400">{{ $alertTypeLabels[$alert->alert_type?->value ?? ''] ?? $alert->alert_type?->value }}</span>
                            <span class="text-gray-500">| {{ $alert->severity?->value ?? $alert->severity }}</span>
                            @if ($alert->booking)
                                <span class="text-gray-500">|
                                    {{ __('cleaning_admin.overview.alerts.booking_ref', ['number' => $alert->booking->booking_number ?? $alert->booking_id]) }}</span>
                            @endif
                        </x-slot>
                        <x-slot name="actions">
                            @if ($alert->booking && method_exists($alert->booking, 'customer') && $alert->booking->customer?->phone)
                                <a href="tel:{{ $alert->booking->customer->phone }}"
                                    class="fi-btn relative inline-grid grid-flow-col items-center justify-center gap-1.5 rounded-lg fi-btn-color-gray fi-btn-size-sm px-2 py-1.5 font-semibold focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 focus-visible:ring-offset-2">{{ __('cleaning_admin.overview.alerts.call_customer') }}</a>
                            @endif
                            @if ($alert->booking && method_exists($alert->booking, 'worker') && filled($alert->booking->worker?->user?->phone))
                                <a href="tel:{{ $alert->booking->worker->user->phone }}"
                                    class="fi-btn relative inline-grid grid-flow-col items-center justify-center gap-1.5 rounded-lg fi-btn-color-gray fi-btn-size-sm px-2 py-1.5 font-semibold focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 focus-visible:ring-offset-2">{{ __('cleaning_admin.overview.alerts.call_worker') }}</a>
                            @endif
                            @if ($alert->status?->value !== 'resolved')
                                <button type="button" wire:click="resolveAlert({{ $alert->id }})"
                                    class="fi-btn relative inline-grid grid-flow-col items-center justify-center gap-1.5 rounded-lg fi-btn-color-success fi-btn-size-sm px-2 py-1.5 font-semibold focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 focus-visible:ring-offset-2">{{ __('cleaning_admin.overview.alerts.resolve') }}</button>
                            @endif
                        </x-slot>
                    </x-filament-hub.alert-strip-row>
                @endforeach
            </div>
        </x-filament::section>
    @endif

    <x-filament::section :heading="__('cleaning_admin.overview.alerts.system_heading')">
        <div class="space-y-2">
            @forelse ($otherAlerts as $alert)
                <x-filament-hub.alert-strip-row tone="neutral">
                    <x-slot name="leading">
                        <span
                            class="font-semibold text-gray-900 dark:text-white">{{ $alertTypeLabels[$alert->alert_type?->value ?? ''] ?? $alert->alert_type?->value }}</span>
                        <span class="text-gray-500 dark:text-gray-400">|
                            {{ $alert->severity?->value ?? $alert->severity }}</span>
                        @if ($alert->booking)
                            <span class="text-gray-500 dark:text-gray-400">|
                                {{ __('cleaning_admin.overview.alerts.booking_ref', ['number' => $alert->booking->booking_number ?? $alert->booking_id]) }}</span>
                        @endif
                    </x-slot>
                    <x-slot name="actions">
                        @if ($alert->booking && method_exists($alert->booking, 'customer') && $alert->booking->customer?->phone)
                            <a href="tel:{{ $alert->booking->customer->phone }}"
                                class="fi-btn relative inline-grid grid-flow-col items-center justify-center gap-1.5 rounded-lg fi-btn-color-gray fi-btn-size-sm px-2 py-1.5 font-semibold focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 focus-visible:ring-offset-2">{{ __('cleaning_admin.overview.alerts.call_customer') }}</a>
                        @endif
                        @if ($alert->booking && method_exists($alert->booking, 'worker') && filled($alert->booking->worker?->user?->phone))
                            <a href="tel:{{ $alert->booking->worker->user->phone }}"
                                class="fi-btn relative inline-grid grid-flow-col items-center justify-center gap-1.5 rounded-lg fi-btn-color-gray fi-btn-size-sm px-2 py-1.5 font-semibold focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 focus-visible:ring-offset-2">{{ __('cleaning_admin.overview.alerts.call_worker') }}</a>
                        @endif
                        @if ($alert->status?->value !== 'resolved')
                            <button type="button" wire:click="resolveAlert({{ $alert->id }})"
                                class="fi-btn relative inline-grid grid-flow-col items-center justify-center gap-1.5 rounded-lg fi-btn-color-success fi-btn-size-sm px-2 py-1.5 font-semibold focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 focus-visible:ring-offset-2">{{ __('cleaning_admin.overview.alerts.resolve') }}</button>
                        @endif
                    </x-slot>
                </x-filament-hub.alert-strip-row>
            @empty
                @if (count($sosAlerts) === 0)
                    <x-filament-hub.empty-state :message="__('cleaning_admin.overview.alerts.none')" class="text-sm" />
                @endif
            @endforelse
        </div>
    </x-filament::section>
</x-filament-hub.page-shell>
