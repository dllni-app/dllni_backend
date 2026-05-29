@props([
    'title',
    'count',
    'items' => [],
    'emptyMessage',
    'tone' => 'neutral',
])

@php
    $toneClasses = match ($tone) {
        'primary' => 'border-primary-200 dark:border-primary-700/60',
        'success' => 'border-emerald-200 dark:border-emerald-700/60',
        'warning' => 'border-amber-200 dark:border-amber-700/60',
        'danger' => 'border-red-200 dark:border-red-700/60',
        'info' => 'border-cyan-200 dark:border-cyan-700/60',
        default => 'border-gray-200 dark:border-gray-700',
    };
@endphp

<div
    {{ $attributes->class(['rounded-xl border bg-white p-5 dark:bg-gray-900 '.$toneClasses]) }}>
    <div class="mb-3 flex items-center justify-between gap-2">
        <h3 class="text-sm font-semibold text-gray-900 dark:text-white">{{ $title }}</h3>
        <span
            class="rounded-full bg-primary-50 px-2 py-0.5 text-xs font-semibold text-primary-700 dark:bg-primary-500/20 dark:text-primary-300">{{ $count }}</span>
    </div>

    @if (empty($items))
        <x-filament-hub.empty-state :message="$emptyMessage" />
    @else
        <div class="space-y-2">
            @foreach ($items as $item)
                <x-filament-hub.link-row
                    :label="$item['label']"
                    :url="$item['url']"
                    :meta="$item['meta'] ?? null"
                    :tone="$item['tone'] ?? 'neutral'"
                    :badge="$item['badge'] ?? null"
                />
            @endforeach
        </div>
    @endif
</div>
