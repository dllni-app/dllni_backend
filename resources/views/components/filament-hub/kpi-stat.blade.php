@props([
    'label',
    'value',
    'hint' => null,
    'url' => null,
    'valueSize' => '2xl',
    'cardPadding' => 'p-5',
    'formatValueAsInteger' => false,
])

@php
    $valueClasses = match ($valueSize) {
        'xl' => 'mt-2 text-xl font-semibold text-gray-900 dark:text-white',
        default => 'mt-2 text-2xl font-bold text-gray-900 dark:text-white',
    };
    $displayValue = $formatValueAsInteger
        ? number_format((int) $value)
        : $value;
    $cardClass = 'rounded-xl border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-900 '.$cardPadding;
    $interactive = filled($url);
@endphp

@if ($interactive)
    <a href="{{ $url }}"
        {{ $attributes->class([$cardClass, 'block transition hover:border-primary-500 hover:shadow focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 focus-visible:ring-offset-2 dark:hover:border-primary-600 dark:focus-visible:ring-offset-gray-900']) }}>
        <div class="text-xs font-medium text-gray-500 dark:text-gray-400">{{ $label }}</div>
        <div class="{{ $valueClasses }}">{{ $displayValue }}</div>
        @if (filled($hint))
            <div class="mt-1 text-xs text-gray-600 dark:text-gray-300">{{ $hint }}</div>
        @endif
    </a>
@else
    <div {{ $attributes->class([$cardClass]) }}>
        <div class="text-xs font-medium text-gray-500 dark:text-gray-400">{{ $label }}</div>
        <div class="{{ $valueClasses }}">{{ $displayValue }}</div>
        @if (filled($hint))
            <div class="mt-1 text-xs text-gray-600 dark:text-gray-300">{{ $hint }}</div>
        @endif
    </div>
@endif
