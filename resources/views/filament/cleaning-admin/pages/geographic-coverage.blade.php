<x-filament-panels::page>
    <x-filament::section heading="تغطية المناطق خلال 7 أيام">
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead>
                    <tr class="border-b">
                        <th class="px-3 py-2 text-right">المنطقة</th>
                        <th class="px-3 py-2 text-right">عدد العمال</th>
                        <th class="px-3 py-2 text-right">الطلب المتوقع</th>
                        <th class="px-3 py-2 text-right">نسبة الضغط</th>
                        <th class="px-3 py-2 text-right">الحالة</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($rows as $row)
                        <tr class="border-b">
                            <td class="px-3 py-2">{{ $row['zone'] }}</td>
                            <td class="px-3 py-2">{{ $row['workers_count'] }}</td>
                            <td class="px-3 py-2">{{ $row['demand_count'] }}</td>
                            <td class="px-3 py-2">{{ $row['coverage_ratio'] }}</td>
                            <td class="px-3 py-2">{{ $row['level'] }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td class="px-3 py-2 text-gray-500" colspan="5">لا توجد بيانات مناطق حتى الآن.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-filament::section>
</x-filament-panels::page>
