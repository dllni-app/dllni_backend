<x-filament-panels::page>
    <div dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}" class="space-y-6">
        <x-filament::section :heading="__('restaurant_admin.inventory.actions.heading')" :description="__('restaurant_admin.inventory.actions.description')">
            <div class="grid gap-3 md:grid-cols-3">
                <a href="{{ $actionUrls['restaurants'] }}"
                    class="rounded-xl border border-gray-200 bg-white px-4 py-3 text-center text-sm font-medium text-gray-900 transition hover:border-primary-500 hover:text-primary-600 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">
                    {{ __('restaurant_admin.inventory.actions.restaurants') }}
                </a>
                <a href="{{ $actionUrls['orders'] }}"
                    class="rounded-xl border border-gray-200 bg-white px-4 py-3 text-center text-sm font-medium text-gray-900 transition hover:border-primary-500 hover:text-primary-600 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">
                    {{ __('restaurant_admin.inventory.actions.orders') }}
                </a>
                <a href="{{ $actionUrls['disputes'] }}"
                    class="rounded-xl border border-gray-200 bg-white px-4 py-3 text-center text-sm font-medium text-gray-900 transition hover:border-primary-500 hover:text-primary-600 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">
                    {{ __('restaurant_admin.inventory.actions.disputes') }}
                </a>
            </div>
        </x-filament::section>

        <x-filament::section :heading="__('restaurant_admin.inventory.low_stock_heading')" :description="__('restaurant_admin.inventory.low_stock_description')">
            @if ($lowStockProducts->isEmpty())
                <p class="text-gray-500 dark:text-gray-400">{{ __('restaurant_admin.inventory.empty_low_stock') }}</p>
            @else
                <x-filament::table>
                    <x-slot:header>
                        <x-filament::table.header>{{ __('restaurant_admin.inventory.restaurant') }}</x-filament::table.header>
                        <x-filament::table.header>{{ __('restaurant_admin.inventory.product') }}</x-filament::table.header>
                        <x-filament::table.header>{{ __('restaurant_admin.inventory.stock_quantity') }}</x-filament::table.header>
                        <x-filament::table.header>{{ __('restaurant_admin.inventory.low_stock_threshold') }}</x-filament::table.header>
                    </x-slot:header>
                    @foreach ($lowStockProducts as $product)
                        <x-filament::table.row>
                            <x-filament::table.cell>
                                @if ($product->restaurant_id)
                                    <a href="{{ \App\Filament\Resources\Restaurants\RestaurantResource::getUrl('view', ['record' => $product->restaurant_id]) }}"
                                        class="text-primary-600 hover:underline">
                                        {{ $product->restaurant?->name ?? '—' }}
                                    </a>
                                @else
                                    —
                                @endif
                            </x-filament::table.cell>
                            <x-filament::table.cell>{{ $product->name }}</x-filament::table.cell>
                            <x-filament::table.cell>{{ $product->stock_quantity ?? 0 }}</x-filament::table.cell>
                            <x-filament::table.cell>{{ $product->low_stock_threshold ?? '—' }}</x-filament::table.cell>
                        </x-filament::table.row>
                    @endforeach
                </x-filament::table>
            @endif
        </x-filament::section>

        <x-filament::section :heading="__('restaurant_admin.inventory.recent_logs_heading')" :description="__('restaurant_admin.inventory.recent_logs_description')">
            @if ($recentInventoryLogs->isEmpty())
                <p class="text-gray-500 dark:text-gray-400">{{ __('restaurant_admin.inventory.empty_logs') }}</p>
            @else
                <x-filament::table>
                    <x-slot:header>
                        <x-filament::table.header>{{ __('restaurant_admin.inventory.restaurant') }}</x-filament::table.header>
                        <x-filament::table.header>{{ __('restaurant_admin.inventory.product') }}</x-filament::table.header>
                        <x-filament::table.header>{{ __('restaurant_admin.inventory.log_type') }}</x-filament::table.header>
                        <x-filament::table.header>{{ __('restaurant_admin.inventory.quantity_change') }}</x-filament::table.header>
                        <x-filament::table.header>{{ __('restaurant_admin.inventory.quantity_after') }}</x-filament::table.header>
                        <x-filament::table.header>{{ __('restaurant_admin.inventory.date') }}</x-filament::table.header>
                    </x-slot:header>
                    @foreach ($recentInventoryLogs as $log)
                        <x-filament::table.row>
                            <x-filament::table.cell>
                                @if ($log->product?->restaurant_id)
                                    <a href="{{ \App\Filament\Resources\Restaurants\RestaurantResource::getUrl('view', ['record' => $log->product->restaurant_id]) }}"
                                        class="text-primary-600 hover:underline">
                                        {{ $log->product?->restaurant?->name ?? '—' }}
                                    </a>
                                @else
                                    —
                                @endif
                            </x-filament::table.cell>
                            <x-filament::table.cell>{{ $log->product?->name ?? '—' }}</x-filament::table.cell>
                            <x-filament::table.cell>{{ $log->type?->value ?? ($log->type ?? '—') }}</x-filament::table.cell>
                            <x-filament::table.cell>{{ $log->quantity_change ?? '—' }}</x-filament::table.cell>
                            <x-filament::table.cell>{{ $log->quantity_after ?? '—' }}</x-filament::table.cell>
                            <x-filament::table.cell>{{ $log->created_at?->format('Y-m-d H:i') ?? '—' }}</x-filament::table.cell>
                        </x-filament::table.row>
                    @endforeach
                </x-filament::table>
            @endif
        </x-filament::section>
    </div>
</x-filament-panels::page>
