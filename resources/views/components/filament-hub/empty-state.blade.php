@props([
    'message',
])

<p {{ $attributes->class(['text-xs text-gray-500 dark:text-gray-400']) }}>
    {{ $message }}
</p>
