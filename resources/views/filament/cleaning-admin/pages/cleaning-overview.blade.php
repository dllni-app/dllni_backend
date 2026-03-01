<x-filament-panels::page>
    <div class="grid gap-4 md:grid-cols-5">
        <x-filament::section>
            <div class="text-sm text-gray-500 dark:text-gray-400">حجوزات التنظيف</div>
            <div class="text-2xl font-bold">{{ $kpis['cleaning_bookings'] }}</div>
        </x-filament::section>
        <x-filament::section>
            <div class="text-sm text-gray-500 dark:text-gray-400">حجوزات المناسبات</div>
            <div class="text-2xl font-bold">{{ $kpis['event_bookings'] }}</div>
        </x-filament::section>
        <x-filament::section>
            <div class="text-sm text-gray-500 dark:text-gray-400">نزاعات مفتوحة</div>
            <div class="text-2xl font-bold">{{ $kpis['open_disputes'] }}</div>
        </x-filament::section>
        <x-filament::section>
            <div class="text-sm text-gray-500 dark:text-gray-400">تنبيهات SOS</div>
            <div class="text-2xl font-bold">{{ $kpis['open_sos'] }}</div>
        </x-filament::section>
        <x-filament::section>
            <div class="text-sm text-gray-500 dark:text-gray-400">تنبيهات نظام جديدة</div>
            <div class="text-2xl font-bold">{{ $kpis['new_system_alerts'] }}</div>
        </x-filament::section>
    </div>

    @if(count($sosAlerts) > 0)
        <x-filament::section heading="تنبيهات حرجة (SOS)" class="rounded-lg border-2 border-red-500 bg-red-50 dark:bg-red-950/30">
            <div class="space-y-2">
                @foreach($sosAlerts as $alert)
                    <div class="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-red-200 bg-white px-3 py-2 dark:border-red-800 dark:bg-gray-900">
                        <div class="text-sm">
                            <span class="font-semibold text-red-700 dark:text-red-400">{{ $alertTypeLabels[$alert->alert_type?->value ?? ''] ?? $alert->alert_type?->value }}</span>
                            <span class="text-gray-500">| {{ $alert->severity?->value ?? $alert->severity }}</span>
                            @if($alert->booking)
                                <span class="text-gray-500">| حجز #{{ $alert->booking->booking_number ?? $alert->booking_id }}</span>
                            @endif
                        </div>
                        <div class="flex flex-wrap items-center gap-2 text-xs">
                            @if($alert->booking && method_exists($alert->booking, 'customer') && $alert->booking->customer?->phone)
                                <a href="tel:{{ $alert->booking->customer->phone }}" class="fi-btn relative grid-flow-col items-center justify-center gap-1.5 rounded-lg fi-btn-color-gray fi-btn-size-sm inline-grid px-2 py-1.5 font-semibold">اتصل بالعميل</a>
                            @endif
                            @if($alert->booking && method_exists($alert->booking, 'worker') && $alert->booking->worker?->user?->phone)
                                <a href="tel:{{ $alert->booking->worker->user->phone ?? '' }}" class="fi-btn relative grid-flow-col items-center justify-center gap-1.5 rounded-lg fi-btn-color-gray fi-btn-size-sm inline-grid px-2 py-1.5 font-semibold">اتصل بالعامل</a>
                            @endif
                            @if($alert->status?->value !== 'resolved')
                                <button type="button" wire:click="resolveAlert({{ $alert->id }})" class="fi-btn relative grid-flow-col items-center justify-center gap-1.5 rounded-lg fi-btn-color-success fi-btn-size-sm inline-grid px-2 py-1.5 font-semibold">حل / إغلاق</button>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        </x-filament::section>
    @endif

    <x-filament::section heading="تنبيهات النظام">
        <div class="space-y-2">
            @forelse ($otherAlerts as $alert)
                <div class="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-gray-200 px-3 py-2 dark:border-gray-700">
                    <div class="text-sm">
                        <span class="font-semibold">{{ $alertTypeLabels[$alert->alert_type?->value ?? ''] ?? $alert->alert_type?->value }}</span>
                        <span class="text-gray-500 dark:text-gray-400">| {{ $alert->severity?->value ?? $alert->severity }}</span>
                        @if($alert->booking)
                            <span class="text-gray-500 dark:text-gray-400">| حجز #{{ $alert->booking->booking_number ?? $alert->booking_id }}</span>
                        @endif
                    </div>
                    <div class="flex flex-wrap items-center gap-2 text-xs">
                        @if($alert->booking && method_exists($alert->booking, 'customer') && $alert->booking->customer?->phone)
                            <a href="tel:{{ $alert->booking->customer->phone }}" class="fi-btn relative grid-flow-col items-center justify-center gap-1.5 rounded-lg fi-btn-color-gray fi-btn-size-sm inline-grid px-2 py-1.5 font-semibold">اتصل بالعميل</a>
                        @endif
                        @if($alert->booking && method_exists($alert->booking, 'worker') && $alert->booking->worker?->user?->phone ?? null)
                            <a href="tel:{{ $alert->booking->worker->user->phone ?? '' }}" class="fi-btn relative grid-flow-col items-center justify-center gap-1.5 rounded-lg fi-btn-color-gray fi-btn-size-sm inline-grid px-2 py-1.5 font-semibold">اتصل بالعامل</a>
                        @endif
                        @if($alert->status?->value !== 'resolved')
                            <button type="button" wire:click="resolveAlert({{ $alert->id }})" class="fi-btn relative grid-flow-col items-center justify-center gap-1.5 rounded-lg fi-btn-color-success fi-btn-size-sm inline-grid px-2 py-1.5 font-semibold">حل / إغلاق</button>
                        @endif
                    </div>
                </div>
            @empty
                @if(count($sosAlerts) === 0)
                    <div class="text-sm text-gray-500 dark:text-gray-400">لا توجد تنبيهات حالية.</div>
                @endif
            @endforelse
        </div>
    </x-filament::section>
</x-filament-panels::page>
