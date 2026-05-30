<x-filament-panels::page>
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="flex items-center gap-2">
            <x-filament::button
                wire:click="$set('filter', 'all')"
                :color="$filter === 'all' ? 'primary' : 'gray'"
                size="sm"
            >
                {{ __('delivery_company.notifications.filters.all') }}
            </x-filament::button>
            <x-filament::button
                wire:click="$set('filter', 'unread')"
                :color="$filter === 'unread' ? 'primary' : 'gray'"
                size="sm"
            >
                {{ __('delivery_company.notifications.filters.unread') }}
                @if ($unreadCount > 0)
                    ({{ $unreadCount }})
                @endif
            </x-filament::button>
        </div>

        @if ($unreadCount > 0)
            <x-filament::button wire:click="markAllAsRead" size="sm" color="gray">
                {{ __('delivery_company.notifications.actions.mark_all_read') }}
            </x-filament::button>
        @endif
    </div>

    <x-filament::section>
        @if ($notifications->isEmpty())
            <p class="text-sm text-gray-500 dark:text-gray-400">
                {{ __('delivery_company.notifications.empty') }}
            </p>
        @else
            <div class="divide-y divide-gray-100 dark:divide-gray-800">
                @foreach ($notifications as $notification)
                    @php
                        $orderId = $notification['data']['orderId'] ?? null;
                        $orderUrl = $this->orderUrl(is_numeric($orderId) ? (int) $orderId : null);
                    @endphp
                    <div @class([
                        'flex items-start justify-between gap-4 py-4',
                        'bg-primary-50/40 dark:bg-primary-950/20' => ! $notification['isRead'],
                    ])>
                        <div class="min-w-0 flex-1">
                            <div class="flex items-center gap-2">
                                <p class="font-medium text-gray-950 dark:text-white">
                                    {{ $notification['title'] }}
                                </p>
                                @if (! $notification['isRead'])
                                    <span class="inline-flex h-2 w-2 rounded-full bg-primary-500"></span>
                                @endif
                            </div>
                            <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">
                                {{ $notification['body'] }}
                            </p>
                            <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">
                                {{ $notification['createdAt'] }}
                            </p>
                            @if ($orderUrl)
                                <a href="{{ $orderUrl }}" class="mt-2 inline-block text-sm text-primary-600 hover:underline dark:text-primary-400">
                                    {{ __('delivery_company.notifications.actions.view_order') }}
                                </a>
                            @endif
                        </div>

                        @if (! $notification['isRead'])
                            <x-filament::button
                                wire:click="markAsRead('{{ $notification['id'] }}')"
                                size="sm"
                                color="gray"
                            >
                                {{ __('delivery_company.notifications.actions.mark_read') }}
                            </x-filament::button>
                        @endif
                    </div>
                @endforeach
            </div>
        @endif
    </x-filament::section>
</x-filament-panels::page>
