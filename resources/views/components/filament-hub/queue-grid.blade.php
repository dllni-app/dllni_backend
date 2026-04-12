@props([
    'columns' => 'lg:grid-cols-2 xl:grid-cols-3',
])

<div {{ $attributes->class(['grid gap-4', $columns]) }}>
    {{ $slot }}
</div>
