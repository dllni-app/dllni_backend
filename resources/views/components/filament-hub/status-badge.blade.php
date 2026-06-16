@props([
    'label',
    'tone' => 'neutral',
])

@php
    $classes = match ($tone) {
        'success' => 'bg-emerald-100 text-emerald-800 dark:bg-emerald-700/30 dark:text-emerald-200',
        'warning' => 'bg-amber-100 text-amber-800 dark:bg-amber-700/30 dark:text-amber-200',
        'danger' => 'bg-red-100 text-red-800 dark:bg-red-700/30 dark:text-red-200',
        'info' => 'bg-cyan-100 text-cyan-800 dark:bg-cyan-700/30 dark:text-cyan-200',
        'primary' => 'bg-primary-100 text-primary-800 dark:bg-primary-700/30 dark:text-primary-200',
        default => 'bg-gray-100 text-gray-700 dark:bg-gray-700/50 dark:text-gray-200',
    };
@endphp

<span {{ $attributes->class(['inline-flex items-center rounded-full px-2 py-0.5 text-xs font-semibold '.$classes]) }}>
    {{ $label }}
</span>
