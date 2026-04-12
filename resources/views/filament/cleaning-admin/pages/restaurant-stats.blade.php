<x-filament-panels::page>
    <div dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}" class="space-y-6">
        <x-filament::section :heading="__('restaurant_admin.stats.summary_heading')" :description="__('restaurant_admin.stats.summary_description')">
            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900">
                    <div class="text-xs text-gray-500 dark:text-gray-400">
                        {{ __('restaurant_admin.stats.summary.total_orders') }}</div>
                    <div class="mt-2 text-xl font-semibold text-gray-900 dark:text-white">
                        {{ number_format((int) ($summary['totalOrders'] ?? 0)) }}</div>
                </div>
                <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900">
                    <div class="text-xs text-gray-500 dark:text-gray-400">
                        {{ __('restaurant_admin.stats.summary.total_revenue') }}</div>
                    <div class="mt-2 text-xl font-semibold text-gray-900 dark:text-white">
                        {{ number_format((float) ($summary['totalRevenue'] ?? 0), 2) }}</div>
                </div>
                <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900">
                    <div class="text-xs text-gray-500 dark:text-gray-400">
                        {{ __('restaurant_admin.stats.summary.average_order_value') }}</div>
                    <div class="mt-2 text-xl font-semibold text-gray-900 dark:text-white">
                        {{ number_format((float) ($summary['averageOrderValue'] ?? 0), 2) }}</div>
                </div>
                <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900">
                    <div class="text-xs text-gray-500 dark:text-gray-400">
                        {{ __('restaurant_admin.stats.summary.tracked_restaurants') }}</div>
                    <div class="mt-2 text-xl font-semibold text-gray-900 dark:text-white">
                        {{ number_format((int) ($summary['trackedRestaurants'] ?? 0)) }}</div>
                </div>
            </div>

            <div class="mt-4 grid gap-3 md:grid-cols-2">
                <a href="{{ $actionUrls['restaurants'] }}"
                    class="rounded-xl border border-gray-200 bg-white px-4 py-3 text-center text-sm font-medium text-gray-900 transition hover:border-primary-500 hover:text-primary-600 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">
                    {{ __('restaurant_admin.stats.actions.restaurants') }}
                </a>
                <a href="{{ $actionUrls['orders'] }}"
                    class="rounded-xl border border-gray-200 bg-white px-4 py-3 text-center text-sm font-medium text-gray-900 transition hover:border-primary-500 hover:text-primary-600 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">
                    {{ __('restaurant_admin.stats.actions.orders') }}
                </a>
            </div>
        </x-filament::section>

        <x-filament::section :heading="__('restaurant_admin.stats.daily_heading')" :description="__('restaurant_admin.stats.daily_description')">
            @if ($dailyStats->isEmpty())
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
                    @foreach ($dailyStats as $stat)
                        <x-filament::table.row>
                            <x-filament::table.cell>
                                @if ($stat->restaurant_id)
                                    <a href="{{ \App\Filament\Resources\Restaurants\RestaurantResource::getUrl('view', ['record' => $stat->restaurant_id]) }}"
                                        class="text-primary-600 hover:underline">
                                        {{ $stat->restaurant?->name ?? '—' }}
                                    </a>
                                @else
                                    —
                                @endif
                            </x-filament::table.cell>
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
            @if ($monthlyStats->isEmpty())
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
                    @foreach ($monthlyStats as $stat)
                        <x-filament::table.row>
                            <x-filament::table.cell>
                                @if ($stat->restaurant_id)
                                    <a href="{{ \App\Filament\Resources\Restaurants\RestaurantResource::getUrl('view', ['record' => $stat->restaurant_id]) }}"
                                        class="text-primary-600 hover:underline">
                                        {{ $stat->restaurant?->name ?? '—' }}
                                    </a>
                                @else
                                    —
                                @endif
                            </x-filament::table.cell>
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
