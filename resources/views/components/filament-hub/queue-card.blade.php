@props([
    'title',
    'count',
    'items' => [],
    'emptyMessage',
])

<div
    {{ $attributes->class(['rounded-xl border border-gray-200 bg-white p-5 dark:border-gray-700 dark:bg-gray-900']) }}>
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
                <x-filament-hub.link-row :label="$item['label']" :url="$item['url']" :meta="$item['meta'] ?? null" />
            @endforeach
        </div>
    @endif
</div>
