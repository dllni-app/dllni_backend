@php
    $workingHours = is_array($getState()) ? $getState() : [];
    $dayLabels = [
        'sunday' => 'الأحد',
        'monday' => 'الإثنين',
        'tuesday' => 'الثلاثاء',
        'wednesday' => 'الأربعاء',
        'thursday' => 'الخميس',
        'friday' => 'الجمعة',
        'saturday' => 'السبت',
    ];

    $formatTime = static function (mixed $time): string {
        if (! is_string($time) || $time === '') {
            return '-';
        }

        return preg_match('/^\d{2}:\d{2}/', $time) === 1 ? substr($time, 0, 5) : $time;
    };

    $extractRanges = static function (mixed $periods) use ($formatTime): array {
        if (! is_array($periods)) {
            return [];
        }

        $ranges = [];
        foreach ($periods as $period) {
            if (! is_array($period)) {
                continue;
            }

            $from = isset($period['from']) && is_string($period['from'])
                ? $period['from']
                : array_key_first($period);
            $to = isset($period['to']) && is_string($period['to'])
                ? $period['to']
                : (is_string($from) ? ($period[$from] ?? null) : null);

            if (! is_string($from) || ! is_string($to)) {
                continue;
            }

            $ranges[] = [
                'from' => $formatTime($from),
                'to' => $formatTime($to),
            ];
        }

        return $ranges;
    };
@endphp

<div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3" dir="rtl">
    @foreach ($dayLabels as $dayKey => $dayLabel)
        @php
            $day = is_array($workingHours[$dayKey] ?? null) ? $workingHours[$dayKey] : [];
            $ranges = $extractRanges($day['data'] ?? []);
            $isAvailable = (bool) ($day['available'] ?? false) && $ranges !== [];
        @endphp

        <article @class([
            'rounded-2xl border p-4 shadow-sm',
            'border-success-200 bg-success-50/50 dark:border-success-700/50 dark:bg-success-950/20' => $isAvailable,
            'border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-900/50' => ! $isAvailable,
        ])>
            <div class="mb-4 flex items-center justify-between gap-3">
                <div class="flex items-center gap-2.5">
                    <span @class([
                        'flex h-10 w-10 items-center justify-center rounded-xl',
                        'bg-success-100 text-success-700 dark:bg-success-400/10 dark:text-success-400' => $isAvailable,
                        'bg-gray-100 text-gray-500 dark:bg-gray-800 dark:text-gray-400' => ! $isAvailable,
                    ])>
                        <x-filament::icon icon="heroicon-o-calendar-days" class="h-5 w-5" />
                    </span>
                    <span class="text-base font-bold text-gray-950 dark:text-white">{{ $dayLabel }}</span>
                </div>

                <span @class([
                    'rounded-full px-2.5 py-1 text-xs font-semibold',
                    'bg-success-100 text-success-700 dark:bg-success-400/10 dark:text-success-400' => $isAvailable,
                    'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-400' => ! $isAvailable,
                ])>
                    {{ $isAvailable ? 'متاح' : 'غير متاح' }}
                </span>
            </div>

            @if ($isAvailable)
                <div class="space-y-3">
                    @foreach ($ranges as $range)
                        <div class="rounded-xl border border-gray-200 bg-white p-3 dark:border-gray-700 dark:bg-gray-900">
                            @if (count($ranges) > 1)
                                <p class="mb-2 text-xs font-semibold text-gray-500 dark:text-gray-400">
                                    الفترة {{ $loop->iteration }}
                                </p>
                            @endif

                            <div class="grid grid-cols-2 gap-2">
                                <div class="rounded-lg bg-gray-50 px-3 py-2.5 dark:bg-gray-800">
                                    <span class="block text-xs text-gray-500 dark:text-gray-400">من</span>
                                    <span class="mt-1 flex items-center gap-2 font-semibold text-success-700 dark:text-success-400" dir="ltr">
                                        <x-filament::icon icon="heroicon-o-clock" class="h-4 w-4 shrink-0" />
                                        {{ $range['from'] }}
                                    </span>
                                </div>

                                <div class="rounded-lg bg-gray-50 px-3 py-2.5 dark:bg-gray-800">
                                    <span class="block text-xs text-gray-500 dark:text-gray-400">إلى</span>
                                    <span class="mt-1 flex items-center gap-2 font-semibold text-success-700 dark:text-success-400" dir="ltr">
                                        <x-filament::icon icon="heroicon-o-clock" class="h-4 w-4 shrink-0" />
                                        {{ $range['to'] }}
                                    </span>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            @else
                <div class="rounded-xl bg-gray-50 px-3 py-4 text-center text-sm text-gray-500 dark:bg-gray-800 dark:text-gray-400">
                    لا توجد فترات عمل مسجلة لهذا اليوم.
                </div>
            @endif
        </article>
    @endforeach
</div>
