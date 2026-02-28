<x-filament-panels::page>
    <div dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}" class="space-y-6">
        <x-filament::section :heading="__('restaurant_admin.time_monitoring.title')">
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="border-b">
                            <th class="px-3 py-2 text-right">{{ __('restaurant_admin.time_monitoring.order') }}</th>
                            <th class="px-3 py-2 text-right">{{ __('restaurant_admin.time_monitoring.restaurant') }}</th>
                            <th class="px-3 py-2 text-right">{{ __('restaurant_admin.time_monitoring.customer') }}</th>
                            <th class="px-3 py-2 text-right">حالة الطلب</th>
                            <th class="px-3 py-2 text-right">{{ __('restaurant_admin.time_monitoring.expected_end') }}</th>
                            <th class="px-3 py-2 text-right">{{ __('restaurant_admin.time_monitoring.minutes_remaining') }}</th>
                            <th class="px-3 py-2 text-right">{{ __('restaurant_admin.time_monitoring.state') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($rows as $row)
                            <tr class="border-b">
                                <td class="px-3 py-2">{{ $row['order_number'] }}</td>
                                <td class="px-3 py-2">{{ $row['restaurant'] }}</td>
                                <td class="px-3 py-2">{{ $row['customer'] }}</td>
                                <td class="px-3 py-2">{{ $row['status'] }}</td>
                                <td class="px-3 py-2">{{ $row['expected_end_at'] }}</td>
                                <td class="px-3 py-2">{{ $row['minutes_remaining'] }}</td>
                                <td class="px-3 py-2">{{ $row['monitoring_state'] }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td class="px-3 py-2 text-gray-500" colspan="7">{{ __('restaurant_admin.time_monitoring.empty') }}</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-filament::section>
    </div>
</x-filament-panels::page>
