@props([
    'title',
    'description' => null,
])

<div
    {{ $attributes->class(['rounded-xl border border-gray-200 bg-white p-5 dark:border-gray-700 dark:bg-gray-900']) }}>
    <h3 class="text-sm font-semibold text-gray-900 dark:text-white">{{ $title }}</h3>
    @if (filled($description))
        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ $description }}</p>
    @endif
    <div class="mt-4 space-y-2">
        {{ $slot }}
    </div>
</div>
