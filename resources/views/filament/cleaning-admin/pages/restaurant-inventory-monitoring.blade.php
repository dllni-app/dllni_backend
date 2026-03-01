<x-filament-panels::page>
    <div dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}" class="space-y-6">
        <x-filament::section :heading="__('restaurant_admin.inventory.low_stock_heading')" :description="__('restaurant_admin.inventory.low_stock_description')">
            @if($lowStockProducts->isEmpty())
                <p class="text-gray-500 dark:text-gray-400">{{ __('restaurant_admin.inventory.empty_low_stock') }}</p>
            @else
                <x-filament::table>
                    <x-slot:header>
                        <x-filament::table.header>{{ __('restaurant_admin.inventory.restaurant') }}</x-filament::table.header>
                        <x-filament::table.header>{{ __('restaurant_admin.inventory.product') }}</x-filament::table.header>
                        <x-filament::table.header>{{ __('restaurant_admin.inventory.stock_quantity') }}</x-filament::table.header>
                        <x-filament::table.header>{{ __('restaurant_admin.inventory.low_stock_threshold') }}</x-filament::table.header>
                    </x-slot:header>
                    @foreach($lowStockProducts as $product)
                        <x-filament::table.row>
                            <x-filament::table.cell>{{ $product->restaurant?->name ?? '—' }}</x-filament::table.cell>
                            <x-filament::table.cell>{{ $product->name }}</x-filament::table.cell>
                            <x-filament::table.cell>{{ $product->stock_quantity ?? 0 }}</x-filament::table.cell>
                            <x-filament::table.cell>{{ $product->low_stock_threshold ?? '—' }}</x-filament::table.cell>
                        </x-filament::table.row>
                    @endforeach
                </x-filament::table>
            @endif
        </x-filament::section>

        <x-filament::section :heading="__('restaurant_admin.inventory.recent_logs_heading')" :description="__('restaurant_admin.inventory.recent_logs_description')">
            @if($recentInventoryLogs->isEmpty())
                <p class="text-gray-500 dark:text-gray-400">{{ __('restaurant_admin.inventory.empty_logs') }}</p>
            @else
                <x-filament::table>
                    <x-slot:header>
                        <x-filament::table.header>{{ __('restaurant_admin.inventory.product') }}</x-filament::table.header>
                        <x-filament::table.header>{{ __('restaurant_admin.inventory.log_type') }}</x-filament::table.header>
                        <x-filament::table.header>{{ __('restaurant_admin.inventory.quantity_change') }}</x-filament::table.header>
                        <x-filament::table.header>{{ __('restaurant_admin.inventory.quantity_after') }}</x-filament::table.header>
                        <x-filament::table.header>{{ __('restaurant_admin.inventory.date') }}</x-filament::table.header>
                    </x-slot:header>
                    @foreach($recentInventoryLogs as $log)
                        <x-filament::table.row>
                            <x-filament::table.cell>{{ $log->product?->name ?? '—' }}</x-filament::table.cell>
                            <x-filament::table.cell>{{ $log->type?->value ?? $log->type ?? '—' }}</x-filament::table.cell>
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
