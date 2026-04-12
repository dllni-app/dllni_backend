@props([
    'columns' => 'lg:grid-cols-3',
])

<div {{ $attributes->class(['grid gap-4', $columns]) }}>
    {{ $slot }}
</div>
