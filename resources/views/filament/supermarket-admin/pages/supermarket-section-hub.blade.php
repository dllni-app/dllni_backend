<x-filament-panels::page>
    <div dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}" class="space-y-6">
        <x-filament::section :heading="__('supermarket_admin.hub.title')" :description="__('supermarket_admin.hub.description')">
            <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                @foreach ($overviewKpis as $metric)
                    <div class="rounded-xl border border-gray-200 bg-white p-5 dark:border-gray-700 dark:bg-gray-900">
                        <div class="text-xs font-medium text-gray-500 dark:text-gray-400">{{ $metric['label'] }}</div>
                        <div class="mt-2 text-2xl font-bold text-gray-900 dark:text-white">{{ $metric['value'] }}</div>
                        <div class="mt-1 text-xs text-gray-600 dark:text-gray-300">{{ $metric['hint'] }}</div>
                    </div>
                @endforeach
            </div>
        </x-filament::section>

        <x-filament::section :heading="__('supermarket_admin.flow.title')" :description="__('supermarket_admin.flow.description')">
            <div class="grid gap-4 lg:grid-cols-3">
                @foreach ($workflowSections as $section)
                    <div class="rounded-xl border border-gray-200 bg-white p-5 dark:border-gray-700 dark:bg-gray-900">
                        <h3 class="text-sm font-semibold text-gray-900 dark:text-white">{{ $section['title'] }}</h3>
                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ $section['description'] }}</p>

                        <div class="mt-4 space-y-2">
                            @foreach ($section['links'] as $link)
                                <a href="{{ $link['url'] }}"
                                    class="block rounded-lg border border-gray-200 px-3 py-2 text-sm transition hover:border-primary-500 hover:bg-gray-50 dark:border-gray-700 dark:hover:bg-gray-800/60">
                                    <div class="flex items-center justify-between gap-2">
                                        <span
                                            class="font-medium text-gray-900 dark:text-white">{{ $link['label'] }}</span>
                                        @if (!empty($link['badge']))
                                            <span
                                                class="rounded-full bg-gray-100 px-2 py-0.5 text-[10px] font-semibold text-gray-700 dark:bg-gray-700 dark:text-gray-200">{{ $link['badge'] }}</span>
                                        @endif
                                    </div>
                                </a>
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </div>
        </x-filament::section>

        <x-filament::section :heading="__('supermarket_admin.queues.title')" :description="__('supermarket_admin.queues.description')">
            <div class="grid gap-4 lg:grid-cols-2 xl:grid-cols-3">
                @foreach ($priorityQueues as $queue)
                    <div class="rounded-xl border border-gray-200 bg-white p-5 dark:border-gray-700 dark:bg-gray-900">
                        <div class="mb-3 flex items-center justify-between gap-2">
                            <h3 class="text-sm font-semibold text-gray-900 dark:text-white">{{ $queue['title'] }}</h3>
                            <span
                                class="rounded-full bg-primary-50 px-2 py-0.5 text-xs font-semibold text-primary-700 dark:bg-primary-500/20 dark:text-primary-300">{{ $queue['count'] }}</span>
                        </div>

                        @if (empty($queue['items']))
                            <p class="text-xs text-gray-500 dark:text-gray-400">
                                {{ __('supermarket_admin.queues.empty') }}</p>
                        @else
                            <div class="space-y-2">
                                @foreach ($queue['items'] as $item)
                                    <a href="{{ $item['url'] }}"
                                        class="block rounded-lg border border-gray-200 px-3 py-2 transition hover:border-primary-500 hover:bg-gray-50 dark:border-gray-700 dark:hover:bg-gray-800/60">
                                        <div class="text-sm font-medium text-gray-900 dark:text-white">
                                            {{ $item['label'] }}</div>
                                        <div class="text-xs text-gray-500 dark:text-gray-400">{{ $item['meta'] }}</div>
                                    </a>
                                @endforeach
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>
        </x-filament::section>

        <x-filament::section :heading="__('supermarket_admin.global.title')" :description="__('supermarket_admin.global.description')">
            <div class="grid gap-3 md:grid-cols-2">
                @foreach ($globalLinks as $link)
                    <a href="{{ $link['url'] }}"
                        class="rounded-xl border border-gray-200 bg-white px-4 py-3 text-sm font-semibold text-gray-900 transition hover:border-primary-500 hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-900 dark:text-white dark:hover:bg-gray-800/60">
                        {{ $link['label'] }}
                    </a>
                @endforeach
            </div>
        </x-filament::section>

        <x-filament::section :heading="__('supermarket_admin.recent_activity.title')" :description="__('supermarket_admin.recent_activity.description')">
            @if (empty($recentActivity))
                <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('supermarket_admin.recent_activity.empty') }}
                </p>
            @else
                <div class="space-y-2">
                    @foreach ($recentActivity as $activity)
                        <div class="rounded-lg border border-gray-200 px-3 py-2 dark:border-gray-700">
                            <div class="text-sm font-medium text-gray-900 dark:text-white">
                                {{ $activity['order_number'] ?? '—' }}</div>
                            <div class="text-xs text-gray-500 dark:text-gray-400">
                                {{ $activity['store_name'] ?? '—' }} • {{ $activity['customer_name'] ?? '—' }} •
                                {{ number_format((float) ($activity['order_total'] ?? 0), 2) }}
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </x-filament::section>
    </div>
</x-filament-panels::page>
