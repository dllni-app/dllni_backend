<x-filament-panels::page>
    <div dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}" class="space-y-6">
        <x-filament::section :heading="__('restaurant_admin.stats.daily_heading')" :description="__('restaurant_admin.stats.daily_description')">
            @if($dailyStats->isEmpty())
                <p class="text-gray-500 dark:text-gray-400">{{ __('restaurant_admin.stats.empty_daily') }}</p>
            @else
                <x-filament::table>
                    <x-slot:header>
                        <x-filament::table.header>{{ __('restaurant_admin.stats.restaurant') }}</x-filament::table.header>
                        <x-filament::table.header>{{ __('restaurant_admin.stats.date') }}</x-filament::table.header>
                        <x-filament::table.header>{{ __('restaurant_admin.stats.orders_count') }}</x-filament::table.header>
                        <x-filament::table.header>{{ __('restaurant_admin.stats.revenue') }}</x-filament::table.header>
                        <x-filament::table.header>{{ __('restaurant_admin.stats.average_order_value') }}</x-filament::table.header>
                    </x-slot:header>
                    @foreach($dailyStats as $stat)
                        <x-filament::table.row>
                            <x-filament::table.cell>{{ $stat->restaurant?->name ?? '—' }}</x-filament::table.cell>
                            <x-filament::table.cell>{{ $stat->stat_date?->format('Y-m-d') ?? '—' }}</x-filament::table.cell>
                            <x-filament::table.cell>{{ $stat->orders_count ?? 0 }}</x-filament::table.cell>
                            <x-filament::table.cell>{{ $stat->revenue !== null ? number_format((float) $stat->revenue, 2) : '—' }}</x-filament::table.cell>
                            <x-filament::table.cell>{{ $stat->average_order_value !== null ? number_format((float) $stat->average_order_value, 2) : '—' }}</x-filament::table.cell>
                        </x-filament::table.row>
                    @endforeach
                </x-filament::table>
            @endif
        </x-filament::section>

        <x-filament::section :heading="__('restaurant_admin.stats.monthly_heading')" :description="__('restaurant_admin.stats.monthly_description')">
            @if($monthlyStats->isEmpty())
                <p class="text-gray-500 dark:text-gray-400">{{ __('restaurant_admin.stats.empty_monthly') }}</p>
            @else
                <x-filament::table>
                    <x-slot:header>
                        <x-filament::table.header>{{ __('restaurant_admin.stats.restaurant') }}</x-filament::table.header>
                        <x-filament::table.header>{{ __('restaurant_admin.stats.year_month') }}</x-filament::table.header>
                        <x-filament::table.header>{{ __('restaurant_admin.stats.orders_count') }}</x-filament::table.header>
                        <x-filament::table.header>{{ __('restaurant_admin.stats.revenue') }}</x-filament::table.header>
                        <x-filament::table.header>{{ __('restaurant_admin.stats.average_order_value') }}</x-filament::table.header>
                    </x-slot:header>
                    @foreach($monthlyStats as $stat)
                        <x-filament::table.row>
                            <x-filament::table.cell>{{ $stat->restaurant?->name ?? '—' }}</x-filament::table.cell>
                            <x-filament::table.cell>{{ $stat->stat_year }}-{{ str_pad((string) $stat->stat_month, 2, '0', STR_PAD_LEFT) }}</x-filament::table.cell>
                            <x-filament::table.cell>{{ $stat->orders_count ?? 0 }}</x-filament::table.cell>
                            <x-filament::table.cell>{{ $stat->revenue !== null ? number_format((float) $stat->revenue, 2) : '—' }}</x-filament::table.cell>
                            <x-filament::table.cell>{{ $stat->average_order_value !== null ? number_format((float) $stat->average_order_value, 2) : '—' }}</x-filament::table.cell>
                        </x-filament::table.row>
                    @endforeach
                </x-filament::table>
            @endif
        </x-filament::section>
    </div>
</x-filament-panels::page>
