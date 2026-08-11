@php
    $days = $getState() ?? [];
@endphp

<div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
    @foreach ($days as $day)
        <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900/50">
            <div class="mb-3 flex items-center justify-between gap-3">
                <span class="font-semibold text-gray-950 dark:text-white">{{ $day['label'] }}</span>

                @if ($day['available'])
                    <span class="rounded-full bg-success-50 px-2.5 py-1 text-xs font-semibold text-success-700 dark:bg-success-400/10 dark:text-success-400">
                        متاح
                    </span>
                @else
                    <span class="rounded-full bg-gray-100 px-2.5 py-1 text-xs font-semibold text-gray-600 dark:bg-gray-800 dark:text-gray-400">
                        غير متاح
                    </span>
                @endif
            </div>

            @if ($day['available'])
                <div class="space-y-2">
                    @foreach ($day['ranges'] as $range)
                        <div class="flex items-center gap-2 rounded-lg bg-gray-50 px-3 py-2 text-sm text-gray-700 dark:bg-gray-800 dark:text-gray-200">
                            <x-filament::icon icon="heroicon-o-clock" class="h-4 w-4 text-primary-500" />
                            <span dir="ltr">{{ $range['label'] }}</span>
                        </div>
                    @endforeach
                </div>
            @else
                <div class="text-sm text-gray-500 dark:text-gray-400">
                    لا توجد فترات عمل مسجلة لهذا اليوم.
                </div>
            @endif
        </div>
    @endforeach
</div>

<p class="mt-3 text-xs text-gray-500 dark:text-gray-400">
    يتم عرض الساعات بعد توحيدها بنفس الدالة المستخدمة في استجابة تطبيق العامل، بما في ذلك الأيام غير المتاحة والفترات المتعددة.
</p>
