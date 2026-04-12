@props([
    'columns' => 'md:grid-cols-2 xl:grid-cols-4',
])

<div {{ $attributes->class(['grid gap-4', $columns]) }}>
    {{ $slot }}
</div>
