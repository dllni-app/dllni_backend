@props([
    'label',
    'url',
    'meta' => null,
    'variant' => 'default',
])

@php
    $base = 'block rounded-lg px-3 py-2 transition';
    $variantClass = $variant === 'soft'
        ? ' border border-gray-100 hover:border-primary-500 dark:border-gray-800'
        : ' border border-gray-200 hover:border-primary-500 hover:bg-gray-50 dark:border-gray-700 dark:hover:bg-gray-800/60';
@endphp

<a href="{{ $url }}"
    {{ $attributes->class([$base.$variantClass, 'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 focus-visible:ring-offset-2 dark:focus-visible:ring-offset-gray-900']) }}>
    <div class="text-sm font-medium text-gray-900 dark:text-white">{{ $label }}</div>
    @if (filled($meta))
        <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ $meta }}</div>
    @endif
</a>
