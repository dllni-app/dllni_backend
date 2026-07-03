@props([
    'label',
    'value',
    'hint' => null,
    'url' => null,
    'tone' => 'neutral',
    'badge' => null,
    'valueSize' => '2xl',
    'cardPadding' => 'p-5',
    'formatValueAsInteger' => false,
    'valueFormat' => null, // integer|currency|raw|null
    'currencyDecimals' => 2,
    'actionEmphasis' => false,
])

@php
    $valueClasses = match ($valueSize) {
        'xl' => 'mt-2 text-xl font-semibold text-gray-900 dark:text-white',
        default => 'mt-2 text-2xl font-bold text-gray-900 dark:text-white',
    };
    $resolvedFormat = $valueFormat
        ?? ($formatValueAsInteger ? 'integer' : 'raw');
    $displayValue = match ($resolvedFormat) {
        'integer' => \App\Filament\Support\AdminUiFormatter::formatNumber((float) $value, 0),
        'currency' => \App\Filament\Support\AdminUiFormatter::formatCurrency((float) $value, $currencyDecimals),
        default => $value,
    };
    $toneClasses = match ($tone) {
        'primary' => 'border-primary-200 dark:border-primary-700/70',
        'success' => 'border-emerald-200 dark:border-emerald-700/60',
        'warning' => 'border-amber-200 dark:border-amber-700/60',
        'danger' => 'border-red-200 dark:border-red-700/60',
        'info' => 'border-cyan-200 dark:border-cyan-700/60',
        default => 'border-gray-200 dark:border-gray-700',
    };
    $cardClass = 'rounded-xl border bg-white dark:bg-gray-900 '.$toneClasses.' '.$cardPadding;
    $interactive = filled($url);
    $actionClass = $actionEmphasis
        ? 'hover:border-primary-600 hover:shadow-md dark:hover:border-primary-500'
        : 'hover:border-primary-500 hover:shadow';
@endphp

@if ($interactive)
    <a href="{{ $url }}"
        {{ $attributes->class([$cardClass, 'block transition '.$actionClass.' focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 focus-visible:ring-offset-2 dark:focus-visible:ring-offset-gray-900']) }}>
        <div class="text-xs font-medium text-gray-500 dark:text-gray-400">{{ $label }}</div>
        <div class="{{ $valueClasses }}">{{ $displayValue }}</div>
        @if (filled($badge))
            <div class="mt-2 inline-flex rounded-full bg-gray-100 px-2 py-0.5 text-[11px] font-semibold text-gray-700 dark:bg-gray-700 dark:text-gray-200">
                {{ $badge }}
            </div>
        @endif
        @if (filled($hint))
            <div class="mt-1 text-xs text-gray-600 dark:text-gray-300">{{ $hint }}</div>
        @endif
    </a>
@else
    <div {{ $attributes->class([$cardClass]) }}>
        <div class="text-xs font-medium text-gray-500 dark:text-gray-400">{{ $label }}</div>
        <div class="{{ $valueClasses }}">{{ $displayValue }}</div>
        @if (filled($badge))
            <div class="mt-2 inline-flex rounded-full bg-gray-100 px-2 py-0.5 text-[11px] font-semibold text-gray-700 dark:bg-gray-700 dark:text-gray-200">
                {{ $badge }}
            </div>
        @endif
        @if (filled($hint))
            <div class="mt-1 text-xs text-gray-600 dark:text-gray-300">{{ $hint }}</div>
        @endif
    </div>
@endif
