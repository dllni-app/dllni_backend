@props([
    'label',
    'url',
    'meta' => null,
    'variant' => 'default',
    'tone' => 'neutral',
    'badge' => null,
])

@php
    $base = 'block rounded-lg px-3 py-2 transition';
    $variantClass = $variant === 'soft'
        ? ' border border-gray-100 hover:border-primary-500 dark:border-gray-800'
        : ' border border-gray-200 hover:border-primary-500 hover:bg-gray-50 dark:border-gray-700 dark:hover:bg-gray-800/60';
    $toneClasses = match ($tone) {
        'primary' => ' border-primary-200 dark:border-primary-700/60',
        'success' => ' border-emerald-200 dark:border-emerald-700/60',
        'warning' => ' border-amber-200 dark:border-amber-700/60',
        'danger' => ' border-red-200 dark:border-red-700/60',
        'info' => ' border-cyan-200 dark:border-cyan-700/60',
        default => '',
    };
@endphp

<a href="{{ $url }}"
    {{ $attributes->class([$base.$variantClass.$toneClasses, 'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 focus-visible:ring-offset-2 dark:focus-visible:ring-offset-gray-900']) }}>
    <div class="flex items-center justify-between gap-2">
        <div class="text-sm font-medium text-gray-900 dark:text-white">{{ $label }}</div>
        @if (filled($badge))
            <span class="rounded-full bg-gray-100 px-2 py-0.5 text-[10px] font-semibold text-gray-700 dark:bg-gray-700 dark:text-gray-200">{{ $badge }}</span>
        @endif
    </div>
    @if (filled($meta))
        <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ $meta }}</div>
    @endif
</a>
