@php
    $areas = $getState() ?? [];
@endphp

<div class="grid gap-2 sm:grid-cols-2 xl:grid-cols-4">
    @foreach ($areas as $area)
        <div class="flex min-h-14 items-center justify-between gap-3 rounded-lg border border-gray-200 bg-white px-3 py-2 dark:border-gray-700 dark:bg-gray-900/50">
            <div class="min-w-0">
                <div class="truncate text-sm font-semibold text-gray-950 dark:text-white">
                    {{ $area['name'] }}
                </div>

                <div class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">
                    {{ $area['city'] ?: '-' }}
                </div>
            </div>

            <span @class([
                'shrink-0 rounded-full px-2 py-0.5 text-[11px] font-semibold',
                'bg-success-50 text-success-700 dark:bg-success-400/10 dark:text-success-400' => ($area['status'] ?? null) === 'نشطة',
                'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-400' => ($area['status'] ?? null) !== 'نشطة',
            ])>
                {{ $area['status'] }}
            </span>
        </div>
    @endforeach
</div>

<p class="mt-2 text-xs text-gray-500 dark:text-gray-400">
    إجمالي مناطق العمل: {{ count($areas) }}
</p>
