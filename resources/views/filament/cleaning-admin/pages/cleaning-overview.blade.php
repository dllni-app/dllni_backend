<x-filament-panels::page>
    <div class="grid gap-4 md:grid-cols-5">
        <x-filament::section>
            <div class="text-sm text-gray-500">حجوزات التنظيف</div>
            <div class="text-2xl font-bold">{{ $kpis['cleaning_bookings'] }}</div>
        </x-filament::section>
        <x-filament::section>
            <div class="text-sm text-gray-500">حجوزات المناسبات</div>
            <div class="text-2xl font-bold">{{ $kpis['event_bookings'] }}</div>
        </x-filament::section>
        <x-filament::section>
            <div class="text-sm text-gray-500">نزاعات مفتوحة</div>
            <div class="text-2xl font-bold">{{ $kpis['open_disputes'] }}</div>
        </x-filament::section>
        <x-filament::section>
            <div class="text-sm text-gray-500">تنبيهات SOS</div>
            <div class="text-2xl font-bold">{{ $kpis['open_sos'] }}</div>
        </x-filament::section>
        <x-filament::section>
            <div class="text-sm text-gray-500">تنبيهات نظام جديدة</div>
            <div class="text-2xl font-bold">{{ $kpis['new_system_alerts'] }}</div>
        </x-filament::section>
    </div>

    <x-filament::section heading="آخر تنبيهات النظام">
        <div class="space-y-2">
            @forelse ($latestAlerts as $alert)
                <div class="flex items-center justify-between rounded-lg border border-gray-200 px-3 py-2">
                    <div class="text-sm">
                        <span class="font-semibold">{{ $alert->alert_type }}</span>
                        <span class="text-gray-500">| {{ $alert->severity }}</span>
                    </div>
                    <div class="text-xs text-gray-500">
                        {{ $alert->status }} - {{ $alert->created_at?->diffForHumans() }}
                    </div>
                </div>
            @empty
                <div class="text-sm text-gray-500">لا توجد تنبيهات حالية.</div>
            @endforelse
        </div>
    </x-filament::section>
</x-filament-panels::page>
