<x-filament-panels::page x-data="{ selectedRow: null }">
    <div dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}" class="space-y-6">
        <x-filament::section :heading="__('restaurant_admin.coverage.title')">
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="border-b">
                            <th class="px-3 py-2 text-right">{{ __('restaurant_admin.coverage.neighborhood') }}</th>
                            <th class="px-3 py-2 text-right">{{ __('restaurant_admin.coverage.active_restaurants') }}</th>
                            <th class="px-3 py-2 text-right">{{ __('restaurant_admin.coverage.avg_daily_demand') }}</th>
                            <th class="px-3 py-2 text-right">{{ __('restaurant_admin.coverage.coverage_ratio') }}</th>
                            <th class="px-3 py-2 text-right">{{ __('restaurant_admin.coverage.coverage_level') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($rows as $row)
                            <tr class="cursor-pointer border-b hover:bg-gray-50 dark:hover:bg-gray-800"
                                @click="selectedRow = selectedRow && selectedRow.neighborhood === @js($row['neighborhood']) ? null : @js($row)">
                                <td class="px-3 py-2">{{ $row['neighborhood'] }}</td>
                                <td class="px-3 py-2">{{ $row['active_restaurants'] }}</td>
                                <td class="px-3 py-2">{{ $row['avg_daily_demand'] }}</td>
                                <td class="px-3 py-2">{{ $row['coverage_ratio'] }}</td>
                                <td class="px-3 py-2">{{ $row['coverage_level'] }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td class="px-3 py-2 text-gray-500" colspan="5">{{ __('restaurant_admin.coverage.empty') }}</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div x-show="selectedRow" x-cloak class="mt-4 rounded-lg border border-gray-200 bg-gray-50 p-4 dark:border-gray-700 dark:bg-gray-800">
                <template x-if="selectedRow">
                    <div>
                        <h4 class="font-semibold" x-text="'{{ __('restaurant_admin.coverage.details_title') }}: ' + selectedRow.neighborhood"></h4>
                        <p class="mt-1 text-sm" x-text="'{{ __('restaurant_admin.coverage.active_restaurants') }}: ' + selectedRow.active_restaurants"></p>
                        <p class="text-sm" x-text="'{{ __('restaurant_admin.coverage.avg_daily_demand') }}: ' + selectedRow.avg_daily_demand"></p>
                        <p class="text-sm" x-text="'{{ __('restaurant_admin.coverage.coverage_level') }}: ' + selectedRow.coverage_level"></p>
                    </div>
                </template>
            </div>
        </x-filament::section>
    </div>
</x-filament-panels::page>
