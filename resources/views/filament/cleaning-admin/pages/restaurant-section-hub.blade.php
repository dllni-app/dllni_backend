<x-filament-panels::page>
    <div dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}" class="space-y-6">
        <x-filament::section :heading="__('restaurant_admin.hub.kpis_heading')" :description="__('restaurant_admin.hub.kpis_description')">
            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                @foreach ($kpis as $kpi)
                    <a href="{{ $kpi['url'] }}"
                        class="rounded-xl border border-gray-200 bg-white p-5 transition hover:border-primary-500 hover:shadow dark:border-gray-700 dark:bg-gray-900">
                        <div class="text-xs text-gray-500 dark:text-gray-400">{{ $kpi['label'] }}</div>
                        <div class="mt-2 text-2xl font-bold text-gray-900 dark:text-white">
                            {{ number_format((int) $kpi['value']) }}</div>
                    </a>
                @endforeach
            </div>
        </x-filament::section>

        <x-filament::section :heading="__('restaurant_admin.hub.priorities_heading')" :description="__('restaurant_admin.hub.priorities_description')">
            <div class="grid gap-4 xl:grid-cols-3">
                <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900">
                    <div class="mb-3 text-sm font-semibold text-gray-900 dark:text-white">
                        {{ __('restaurant_admin.hub.priorities.restaurants') }}</div>
                    <div class="space-y-2 text-sm">
                        @forelse($priorityRestaurants as $restaurant)
                            <a href="{{ \App\Filament\Resources\Restaurants\RestaurantResource::getUrl('view', ['record' => $restaurant->id]) }}"
                                class="block rounded-lg border border-gray-100 px-3 py-2 transition hover:border-primary-500 dark:border-gray-800">
                                <div class="font-medium text-gray-900 dark:text-white">{{ $restaurant->name }}</div>
                                <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                    {{ __('restaurant_admin.hub.reputation_score') }}:
                                    {{ (int) ($restaurant->reputation_score ?? 0) }}
                                    • {{ __('restaurant_admin.hub.warning_count') }}:
                                    {{ (int) ($restaurant->warning_count ?? 0) }}
                                </div>
                            </a>
                        @empty
                            <p class="text-xs text-gray-500 dark:text-gray-400">
                                {{ __('restaurant_admin.hub.empty_state') }}</p>
                        @endforelse
                    </div>
                </div>

                <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900">
                    <div class="mb-3 text-sm font-semibold text-gray-900 dark:text-white">
                        {{ __('restaurant_admin.hub.priorities.disputes') }}</div>
                    <div class="space-y-2 text-sm">
                        @forelse($openDisputes as $dispute)
                            <a href="{{ \App\Filament\Resources\RestaurantDisputes\RestaurantOrderDisputeResource::getUrl('view', ['record' => $dispute->id]) }}"
                                class="block rounded-lg border border-gray-100 px-3 py-2 transition hover:border-primary-500 dark:border-gray-800">
                                <div class="font-medium text-gray-900 dark:text-white">{{ $dispute->ticket_number }}
                                </div>
                                <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                    {{ $dispute->order?->restaurant?->name ?? '—' }}
                                    @php
                                        $disputeStatus = is_object($dispute->status)
                                            ? $dispute->status->value
                                            : $dispute->status ?? 'open';
                                    @endphp
                                    • {{ __('restaurant_admin.hub.status') }}:
                                    {{ __('restaurant_admin.enums.dispute_status.' . $disputeStatus) }}
                                </div>
                            </a>
                        @empty
                            <p class="text-xs text-gray-500 dark:text-gray-400">
                                {{ __('restaurant_admin.hub.empty_state') }}</p>
                        @endforelse
                    </div>
                </div>

                <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900">
                    <div class="mb-3 text-sm font-semibold text-gray-900 dark:text-white">
                        {{ __('restaurant_admin.hub.priorities.inventory') }}</div>
                    <div class="space-y-2 text-sm">
                        @forelse($criticalProducts as $product)
                            <a href="{{ \App\Filament\Resources\Restaurants\RestaurantResource::getUrl('view', ['record' => $product->restaurant_id]) }}"
                                class="block rounded-lg border border-gray-100 px-3 py-2 transition hover:border-primary-500 dark:border-gray-800">
                                <div class="font-medium text-gray-900 dark:text-white">{{ $product->name }}</div>
                                <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                    {{ $product->restaurant?->name ?? '—' }}
                                    • {{ __('restaurant_admin.inventory.stock_quantity') }}:
                                    {{ (int) ($product->stock_quantity ?? 0) }}
                                    • {{ __('restaurant_admin.inventory.low_stock_threshold') }}:
                                    {{ (int) ($product->low_stock_threshold ?? 0) }}
                                </div>
                            </a>
                        @empty
                            <p class="text-xs text-gray-500 dark:text-gray-400">
                                {{ __('restaurant_admin.hub.empty_state') }}</p>
                        @endforelse
                    </div>
                </div>
            </div>
        </x-filament::section>

        <x-filament::section :heading="__('restaurant_admin.hub.quick_actions.heading')" :description="__('restaurant_admin.hub.quick_actions.description')">
            <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-5">
                @foreach ($quickActions as $action)
                    <a href="{{ $action['url'] }}"
                        class="rounded-xl border border-gray-200 bg-white px-4 py-3 text-center text-sm font-medium text-gray-900 transition hover:border-primary-500 hover:text-primary-600 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">
                        {{ $action['label'] }}
                    </a>
                @endforeach
            </div>
        </x-filament::section>

        <x-filament::section :heading="__('restaurant_admin.hub.checklist.heading')" :description="__('restaurant_admin.hub.checklist.description')">
            <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                @foreach ($checklist as $item)
                    <a href="{{ $item['url'] }}"
                        class="rounded-xl border border-gray-200 bg-white p-4 transition hover:border-primary-500 hover:shadow dark:border-gray-700 dark:bg-gray-900">
                        <div class="text-xs text-gray-500 dark:text-gray-400">{{ $item['label'] }}</div>
                        <div class="mt-2 text-xl font-semibold text-gray-900 dark:text-white">
                            {{ number_format((int) $item['value']) }}</div>
                    </a>
                @endforeach
            </div>
        </x-filament::section>
    </div>
</x-filament-panels::page>
