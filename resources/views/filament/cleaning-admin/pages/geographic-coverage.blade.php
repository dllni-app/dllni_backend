<x-filament-panels::page x-data="{ selectedRow: null }">
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
                        <tr
                            class="cursor-pointer border-b hover:bg-gray-50 dark:hover:bg-gray-800"
                            @click="selectedRow = selectedRow && selectedRow.zone === @js($row['zone']) ? null : @js($row)"
                        >
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
        <div x-show="selectedRow" x-cloak class="mt-4 rounded-lg border border-gray-200 bg-gray-50 p-4 dark:border-gray-700 dark:bg-gray-800">
            <template x-if="selectedRow">
                <div>
                    <h4 class="font-semibold" x-text="'الحي: ' + selectedRow.zone"></h4>
                    <p class="mt-1 text-sm" x-text="'عدد العمال الذين يغطون الحي: ' + selectedRow.workers_count"></p>
                    <p class="text-sm" x-text="'متوسط الطلبات (7 أيام): ' + selectedRow.demand_count"></p>
                    <p class="text-sm" x-text="'نسبة التغطية: ' + selectedRow.level"></p>
                </div>
            </template>
        </div>
    </x-filament::section>
</x-filament-panels::page>
