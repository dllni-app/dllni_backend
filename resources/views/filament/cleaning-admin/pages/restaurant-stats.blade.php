<x-filament-hub.page-shell>
    <x-filament::section :heading="__('restaurant_admin.stats.summary_heading')" :description="__('restaurant_admin.stats.summary_description')">
        <x-filament-hub.filter-toolbar
            search-model="search"
            :search-placeholder="__('restaurant_admin.filters.search_placeholder')"
            status-model="revenueState"
            :status-options="$filterOptions['revenueState']"
            range-model="dateRange"
            :range-options="$filterOptions['range']"
        />

        <div class="mt-4 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <x-filament-hub.kpi-stat
                :label="__('restaurant_admin.stats.summary.total_orders')"
                :value="$summary['totalOrders'] ?? 0"
                tone="primary"
                format-value-as-integer
                card-padding="p-4"
                value-size="xl"
            />
            <x-filament-hub.kpi-stat
                :label="__('restaurant_admin.stats.summary.total_revenue')"
                :value="$summary['totalRevenue'] ?? 0"
                tone="success"
                value-format="currency"
                card-padding="p-4"
                value-size="xl"
            />
            <x-filament-hub.kpi-stat
                :label="__('restaurant_admin.stats.summary.average_order_value')"
                :value="$summary['averageOrderValue'] ?? 0"
                tone="info"
                value-format="currency"
                card-padding="p-4"
                value-size="xl"
            />
            <x-filament-hub.kpi-stat
                :label="__('restaurant_admin.stats.summary.tracked_restaurants')"
                :value="$summary['trackedRestaurants'] ?? 0"
                tone="warning"
                format-value-as-integer
                card-padding="p-4"
                value-size="xl"
            />
        </div>

        <div class="mt-4 grid gap-3 md:grid-cols-3">
            <a href="{{ $actionUrls['hub'] }}"
                class="rounded-xl border border-primary-200 bg-primary-50 px-4 py-3 text-center text-sm font-semibold text-primary-700 transition hover:border-primary-600 hover:shadow-sm dark:border-primary-700/60 dark:bg-primary-900/20 dark:text-primary-300">
                {{ __('restaurant_admin.stats.actions.hub') }}
            </a>
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
            <x-filament-hub.empty-state :message="__('restaurant_admin.stats.empty_daily')" />
        @else
            <div class="overflow-x-auto rounded-xl border border-gray-200 dark:border-gray-700">
                <table class="min-w-full divide-y divide-gray-200 text-sm dark:divide-gray-700">
                    <thead class="bg-gray-50 dark:bg-gray-800/50">
                        <tr>
                            <th class="px-3 py-2 text-start font-medium text-gray-700 dark:text-gray-300">{{ __('restaurant_admin.stats.restaurant') }}</th>
                            <th class="px-3 py-2 text-start font-medium text-gray-700 dark:text-gray-300">{{ __('restaurant_admin.stats.date') }}</th>
                            <th class="px-3 py-2 text-start font-medium text-gray-700 dark:text-gray-300">{{ __('restaurant_admin.stats.orders_count') }}</th>
                            <th class="px-3 py-2 text-start font-medium text-gray-700 dark:text-gray-300">{{ __('restaurant_admin.stats.revenue') }}</th>
                            <th class="px-3 py-2 text-start font-medium text-gray-700 dark:text-gray-300">{{ __('restaurant_admin.stats.average_order_value') }}</th>
                            <th class="px-3 py-2 text-start font-medium text-gray-700 dark:text-gray-300">{{ __('restaurant_admin.filters.status') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 bg-white dark:divide-gray-700 dark:bg-gray-900">
                        @foreach ($dailyStats as $stat)
                            @php
                                $hasRevenue = (float) ($stat->revenue ?? 0) > 0;
                            @endphp
                            <tr class="hover:bg-gray-50 dark:hover:bg-gray-800/80">
                                <td class="px-3 py-2 text-gray-900 dark:text-gray-100">
                                    @if ($stat->restaurant_id)
                                        <a href="{{ \App\Filament\Resources\Restaurants\RestaurantResource::getUrl('view', ['record' => $stat->restaurant_id]) }}"
                                            class="text-primary-600 hover:underline">
                                            {{ $stat->restaurant?->name ?? '-' }}
                                        </a>
                                    @else
                                        -
                                    @endif
                                </td>
                                <td class="px-3 py-2 text-gray-900 dark:text-gray-100">{{ $stat->stat_date?->format('Y-m-d') ?? '-' }}</td>
                                <td class="px-3 py-2 text-gray-900 dark:text-gray-100">{{ \App\Filament\Support\AdminUiFormatter::formatNumber((int) ($stat->orders_count ?? 0), 0) }}</td>
                                <td class="px-3 py-2 text-gray-900 dark:text-gray-100">{{ $stat->revenue !== null ? \App\Filament\Support\AdminUiFormatter::formatCurrency((float) $stat->revenue) : '-' }}</td>
                                <td class="px-3 py-2 text-gray-900 dark:text-gray-100">{{ $stat->average_order_value !== null ? \App\Filament\Support\AdminUiFormatter::formatCurrency((float) $stat->average_order_value) : '-' }}</td>
                                <td class="px-3 py-2 text-gray-900 dark:text-gray-100">
                                    <x-filament-hub.status-badge
                                        :label="$hasRevenue ? __('restaurant_admin.filters.with_revenue') : __('restaurant_admin.filters.without_revenue')"
                                        :tone="$hasRevenue ? 'success' : 'warning'"
                                    />
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>

    <x-filament::section :heading="__('restaurant_admin.stats.monthly_heading')" :description="__('restaurant_admin.stats.monthly_description')">
        @if ($monthlyStats->isEmpty())
            <x-filament-hub.empty-state :message="__('restaurant_admin.stats.empty_monthly')" />
        @else
            <div class="overflow-x-auto rounded-xl border border-gray-200 dark:border-gray-700">
                <table class="min-w-full divide-y divide-gray-200 text-sm dark:divide-gray-700">
                    <thead class="bg-gray-50 dark:bg-gray-800/50">
                        <tr>
                            <th class="px-3 py-2 text-start font-medium text-gray-700 dark:text-gray-300">{{ __('restaurant_admin.stats.restaurant') }}</th>
                            <th class="px-3 py-2 text-start font-medium text-gray-700 dark:text-gray-300">{{ __('restaurant_admin.stats.year_month') }}</th>
                            <th class="px-3 py-2 text-start font-medium text-gray-700 dark:text-gray-300">{{ __('restaurant_admin.stats.orders_count') }}</th>
                            <th class="px-3 py-2 text-start font-medium text-gray-700 dark:text-gray-300">{{ __('restaurant_admin.stats.revenue') }}</th>
                            <th class="px-3 py-2 text-start font-medium text-gray-700 dark:text-gray-300">{{ __('restaurant_admin.stats.average_order_value') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 bg-white dark:divide-gray-700 dark:bg-gray-900">
                        @foreach ($monthlyStats as $stat)
                            <tr class="hover:bg-gray-50 dark:hover:bg-gray-800/80">
                                <td class="px-3 py-2 text-gray-900 dark:text-gray-100">
                                    @if ($stat->restaurant_id)
                                        <a href="{{ \App\Filament\Resources\Restaurants\RestaurantResource::getUrl('view', ['record' => $stat->restaurant_id]) }}"
                                            class="text-primary-600 hover:underline">
                                            {{ $stat->restaurant?->name ?? '-' }}
                                        </a>
                                    @else
                                        -
                                    @endif
                                </td>
                                <td class="px-3 py-2 text-gray-900 dark:text-gray-100">{{ $stat->stat_year }}-{{ str_pad((string) $stat->stat_month, 2, '0', STR_PAD_LEFT) }}</td>
                                <td class="px-3 py-2 text-gray-900 dark:text-gray-100">{{ \App\Filament\Support\AdminUiFormatter::formatNumber((int) ($stat->orders_count ?? 0), 0) }}</td>
                                <td class="px-3 py-2 text-gray-900 dark:text-gray-100">{{ $stat->revenue !== null ? \App\Filament\Support\AdminUiFormatter::formatCurrency((float) $stat->revenue) : '-' }}</td>
                                <td class="px-3 py-2 text-gray-900 dark:text-gray-100">{{ $stat->average_order_value !== null ? \App\Filament\Support\AdminUiFormatter::formatCurrency((float) $stat->average_order_value) : '-' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>
</x-filament-hub.page-shell>
