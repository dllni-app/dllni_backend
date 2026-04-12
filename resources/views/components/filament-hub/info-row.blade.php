@props([
    'title',
    'subtitle' => null,
])

<div {{ $attributes->class(['rounded-lg border border-gray-200 px-3 py-2 dark:border-gray-700']) }}>
    <div class="text-sm font-medium text-gray-900 dark:text-white">{{ $title }}</div>
    @if (filled($subtitle))
        <div class="text-xs text-gray-500 dark:text-gray-400">{{ $subtitle }}</div>
    @endif
</div>
