<x-filament-panels::page>
    <div dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}" class="space-y-6">
        <x-filament::section :heading="__('supermarket_admin.stats.summary_heading')"
            :description="__('supermarket_admin.stats.summary_description')">
            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <div
                    class="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900">
                    <div class="text-xs text-gray-500 dark:text-gray-400">
                        {{ __('supermarket_admin.stats.summary.total_orders') }}</div>
                    <div class="mt-2 text-xl font-semibold text-gray-900 dark:text-white">
                        {{ number_format((int) ($summary['totalOrders'] ?? 0)) }}</div>
                </div>
                <div
                    class="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900">
                    <div class="text-xs text-gray-500 dark:text-gray-400">
                        {{ __('supermarket_admin.stats.summary.total_revenue') }}</div>
                    <div class="mt-2 text-xl font-semibold text-gray-900 dark:text-white">
                        {{ number_format((float) ($summary['totalRevenue'] ?? 0), 2) }}</div>
                </div>
                <div
                    class="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900">
                    <div class="text-xs text-gray-500 dark:text-gray-400">
                        {{ __('supermarket_admin.stats.summary.average_order_value') }}</div>
                    <div class="mt-2 text-xl font-semibold text-gray-900 dark:text-white">
                        {{ number_format((float) ($summary['averageOrderValue'] ?? 0), 2) }}</div>
                </div>
                <div
                    class="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900">
                    <div class="text-xs text-gray-500 dark:text-gray-400">
                        {{ __('supermarket_admin.stats.summary.tracked_stores') }}</div>
                    <div class="mt-2 text-xl font-semibold text-gray-900 dark:text-white">
                        {{ number_format((int) ($summary['trackedStores'] ?? 0)) }}</div>
                </div>
            </div>

            <div class="mt-4 grid gap-3 md:grid-cols-2">
                <a href="{{ $actionUrls['stores'] }}"
                    class="rounded-xl border border-gray-200 bg-white px-4 py-3 text-center text-sm font-medium text-gray-900 transition hover:border-primary-500 hover:text-primary-600 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">
                    {{ __('supermarket_admin.stats.actions.stores') }}
                </a>
                <a href="{{ $actionUrls['orders'] }}"
                    class="rounded-xl border border-gray-200 bg-white px-4 py-3 text-center text-sm font-medium text-gray-900 transition hover:border-primary-500 hover:text-primary-600 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">
                    {{ __('supermarket_admin.stats.actions.orders') }}
                </a>
            </div>
        </x-filament::section>

        <x-filament::section :heading="__('supermarket_admin.stats.daily_heading')"
            :description="__('supermarket_admin.stats.daily_description')">
            @if ($dailyStats->isEmpty())
                <p class="text-gray-500 dark:text-gray-400">{{ __('supermarket_admin.stats.empty_daily') }}</p>
            @else
                <div class="overflow-x-auto rounded-xl border border-gray-200 dark:border-gray-700">
                    <table class="min-w-full divide-y divide-gray-200 text-sm dark:divide-gray-700">
                        <thead class="bg-gray-50 dark:bg-gray-800/50">
                            <tr>
                                <th class="px-3 py-2 text-start font-medium text-gray-700 dark:text-gray-300">
                                    {{ __('supermarket_admin.stats.store') }}</th>
                                <th class="px-3 py-2 text-start font-medium text-gray-700 dark:text-gray-300">
                                    {{ __('supermarket_admin.stats.date') }}</th>
                                <th class="px-3 py-2 text-start font-medium text-gray-700 dark:text-gray-300">
                                    {{ __('supermarket_admin.stats.orders_count') }}</th>
                                <th class="px-3 py-2 text-start font-medium text-gray-700 dark:text-gray-300">
                                    {{ __('supermarket_admin.stats.revenue') }}</th>
                                <th class="px-3 py-2 text-start font-medium text-gray-700 dark:text-gray-300">
                                    {{ __('supermarket_admin.stats.average_order_value') }}</th>
                                <th class="px-3 py-2 text-start font-medium text-gray-700 dark:text-gray-300">
                                    {{ __('supermarket_admin.stats.unique_customers') }}</th>
                                <th class="px-3 py-2 text-start font-medium text-gray-700 dark:text-gray-300">
                                    {{ __('supermarket_admin.stats.new_customers') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 bg-white dark:divide-gray-700 dark:bg-gray-900">
                            @foreach ($dailyStats as $stat)
                                @php
                                    $ordersCount = (int) ($stat->orders_count ?? 0);
                                    $revenue = (float) ($stat->orders_revenue ?? 0);
                                    $avgTicket = $ordersCount > 0 ? round($revenue / $ordersCount, 2) : null;
                                @endphp
                                <tr class="hover:bg-gray-50 dark:hover:bg-gray-800/80">
                                    <td class="px-3 py-2 text-gray-900 dark:text-gray-100">
                                        @if ($stat->store_id)
                                            <a href="{{ \App\Filament\Resources\SmStores\SmStoreResource::getUrl('view', ['record' => $stat->store_id]) }}"
                                                class="text-primary-600 hover:underline dark:text-primary-400">
                                                {{ $stat->store?->name ?? '—' }}
                                            </a>
                                        @else
                                            —
                                        @endif
                                    </td>
                                    <td class="px-3 py-2 text-gray-900 dark:text-gray-100">
                                        {{ $stat->date?->format('Y-m-d') ?? '—' }}</td>
                                    <td class="px-3 py-2 text-gray-900 dark:text-gray-100">{{ $ordersCount }}</td>
                                    <td class="px-3 py-2 text-gray-900 dark:text-gray-100">{{ number_format($revenue, 2) }}
                                    </td>
                                    <td class="px-3 py-2 text-gray-900 dark:text-gray-100">
                                        {{ $avgTicket !== null ? number_format($avgTicket, 2) : '—' }}</td>
                                    <td class="px-3 py-2 text-gray-900 dark:text-gray-100">
                                        {{ (int) ($stat->unique_customers ?? 0) }}</td>
                                    <td class="px-3 py-2 text-gray-900 dark:text-gray-100">
                                        {{ (int) ($stat->new_customers ?? 0) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-filament::section>
    </div>
</x-filament-panels::page>
