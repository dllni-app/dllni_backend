<x-filament-hub.page-shell>
    <x-filament::section :heading="__('supermarket_admin.hub.title')" :description="__('supermarket_admin.hub.description')">
        <x-filament-hub.kpi-grid columns="md:grid-cols-2 xl:grid-cols-4">
            @foreach ($overviewKpis as $metric)
                <x-filament-hub.kpi-stat :label="$metric['label']" :value="$metric['value']" :hint="$metric['hint'] ?? null" />
            @endforeach
        </x-filament-hub.kpi-grid>
    </x-filament::section>

    <x-filament::section :heading="__('supermarket_admin.flow.title')" :description="__('supermarket_admin.flow.description')">
        <x-filament-hub.lane-grid>
            @foreach ($workflowSections as $section)
                <x-filament-hub.lane-card :title="$section['title']" :description="$section['description'] ?? null">
                    @foreach ($section['links'] as $link)
                        <x-filament-hub.workflow-link :label="$link['label']" :url="$link['url']"
                            :badge="$link['badge'] ?? null" />
                    @endforeach
                </x-filament-hub.lane-card>
            @endforeach
        </x-filament-hub.lane-grid>
    </x-filament::section>

    <x-filament::section :heading="__('supermarket_admin.reference.title')"
        :description="__('supermarket_admin.reference.description')">
        <x-filament-hub.lane-grid columns="md:grid-cols-2">
            <x-filament-hub.lane-card :title="__('supermarket_admin.reference.card_title')" :description="null">
                @foreach ($referenceLinks as $link)
                    <x-filament-hub.workflow-link :label="$link['label']" :url="$link['url']"
                        :badge="$link['badge'] ?? null" />
                @endforeach
            </x-filament-hub.lane-card>
        </x-filament-hub.lane-grid>
    </x-filament::section>

    <x-filament::section :heading="__('supermarket_admin.attention.section_title')"
        :description="__('supermarket_admin.attention.section_description')">
        <div class="space-y-8">
            @foreach ($attentionGroups as $group)
                <div>
                    <h3 class="text-sm font-semibold text-gray-900 dark:text-white">{{ $group['title'] }}</h3>
                    @if (!empty($group['description']))
                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ $group['description'] }}</p>
                    @endif
                    <x-filament-hub.queue-grid class="mt-4">
                        @foreach ($group['queues'] as $queue)
                            <x-filament-hub.queue-card :title="$queue['title']" :count="$queue['count']"
                                :items="$queue['items'] ?? []" :empty-message="$queue['emptyMessage']" />
                        @endforeach
                    </x-filament-hub.queue-grid>
                </div>
            @endforeach
        </div>
    </x-filament::section>

    <x-filament::section :heading="__('supermarket_admin.recent_activity.title')"
        :description="__('supermarket_admin.recent_activity.description')">
        @if (empty($recentActivity))
            <x-filament-hub.empty-state :message="__('supermarket_admin.recent_activity.empty')" />
        @else
            <div class="space-y-2">
                @foreach ($recentActivity as $activity)
                    <x-filament-hub.info-row :title="$activity['order_number'] ?? '—'"
                        :subtitle="($activity['store_name'] ?? '—').' • '.($activity['customer_name'] ?? '—').' • '.number_format((float) ($activity['order_total'] ?? 0), 2)" />
                @endforeach
            </div>
        @endif
    </x-filament::section>
</x-filament-hub.page-shell>
