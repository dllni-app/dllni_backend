@props([
    'title',
    'items' => [],
    'emptyMessage',
])

<div
    {{ $attributes->class(['rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900']) }}>
    <div class="mb-3 text-sm font-semibold text-gray-900 dark:text-white">{{ $title }}</div>
    <div class="space-y-2 text-sm">
        @forelse ($items as $item)
            <x-filament-hub.link-row :label="$item['label']" :url="$item['url']" :meta="$item['meta'] ?? null" variant="soft" />
        @empty
            <x-filament-hub.empty-state :message="$emptyMessage" />
        @endforelse
    </div>
</div>
