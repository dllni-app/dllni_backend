@props([
    'links' => [],
    'columns' => 'grid gap-3 md:grid-cols-2',
    'centered' => false,
])

<div {{ $attributes->class([$columns]) }}>
    @foreach ($links as $link)
        <a href="{{ $link['url'] }}"
            @class([
                'rounded-xl border border-gray-200 bg-white px-4 py-3 text-sm font-semibold text-gray-900 transition hover:border-primary-500 hover:bg-gray-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 focus-visible:ring-offset-2 dark:border-gray-700 dark:bg-gray-900 dark:text-white dark:hover:bg-gray-800/60 dark:focus-visible:ring-offset-gray-900' => ! $centered,
                'rounded-xl border border-gray-200 bg-white px-4 py-3 text-center text-sm font-medium text-gray-900 transition hover:border-primary-500 hover:text-primary-600 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 focus-visible:ring-offset-2 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 dark:focus-visible:ring-offset-gray-900' => $centered,
            ])>
            {{ $link['label'] }}
        </a>
    @endforeach
</div>
