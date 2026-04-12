@props([
    'label',
    'url',
    'badge' => null,
])

<a href="{{ $url }}"
    {{ $attributes->class(['block rounded-lg border border-gray-200 px-3 py-2 text-sm transition hover:border-primary-500 hover:bg-gray-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 focus-visible:ring-offset-2 dark:border-gray-700 dark:hover:bg-gray-800/60 dark:focus-visible:ring-offset-gray-900']) }}>
    <div class="flex items-center justify-between gap-2">
        <span class="font-medium text-gray-900 dark:text-white">{{ $label }}</span>
        @if (filled($badge))
            <span
                class="rounded-full bg-gray-100 px-2 py-0.5 text-[10px] font-semibold text-gray-700 dark:bg-gray-700 dark:text-gray-200">{{ $badge }}</span>
        @endif
    </div>
</a>
