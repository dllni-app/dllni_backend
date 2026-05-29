@props([
    'links' => [],
    'columns' => 'grid gap-3 md:grid-cols-2',
    'centered' => false,
    'actionEmphasis' => false,
])

<div {{ $attributes->class([$columns]) }}>
    @foreach ($links as $link)
        @php
            $tone = $link['tone'] ?? 'neutral';
            $toneClasses = match ($tone) {
                'primary' => 'border-primary-200 dark:border-primary-700/60',
                'success' => 'border-emerald-200 dark:border-emerald-700/60',
                'warning' => 'border-amber-200 dark:border-amber-700/60',
                'danger' => 'border-red-200 dark:border-red-700/60',
                'info' => 'border-cyan-200 dark:border-cyan-700/60',
                default => 'border-gray-200 dark:border-gray-700',
            };
        @endphp
        <a href="{{ $link['url'] }}"
            @class([
                'rounded-xl border bg-white px-4 py-3 text-sm font-semibold text-gray-900 transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 focus-visible:ring-offset-2 dark:bg-gray-900 dark:text-white dark:focus-visible:ring-offset-gray-900 '.$toneClasses => ! $centered,
                'rounded-xl border bg-white px-4 py-3 text-center text-sm font-medium text-gray-900 transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 focus-visible:ring-offset-2 dark:bg-gray-900 dark:text-gray-100 dark:focus-visible:ring-offset-gray-900 '.$toneClasses => $centered,
                'hover:border-primary-600 hover:shadow-md dark:hover:border-primary-500' => $actionEmphasis,
                'hover:border-primary-500 hover:bg-gray-50 dark:hover:bg-gray-800/60' => ! $actionEmphasis,
            ])>
            {{ $link['label'] }}
        </a>
    @endforeach
</div>
