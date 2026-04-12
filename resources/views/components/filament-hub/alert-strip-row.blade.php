@props([
    'tone' => 'neutral',
])

@php
    $wrapper = match ($tone) {
        'danger' => 'flex flex-wrap items-center justify-between gap-2 rounded-lg border border-red-200 bg-white px-3 py-2 dark:border-red-800 dark:bg-gray-900',
        default => 'flex flex-wrap items-center justify-between gap-2 rounded-lg border border-gray-200 bg-white px-3 py-2 dark:border-gray-700 dark:bg-gray-900',
    };
@endphp

<div {{ $attributes->class([$wrapper]) }}>
    <div class="min-w-0 flex-1 text-sm">
        {{ $leading }}
    </div>
    <div class="flex flex-shrink-0 flex-wrap items-center gap-2 text-xs">
        {{ $actions }}
    </div>
</div>
