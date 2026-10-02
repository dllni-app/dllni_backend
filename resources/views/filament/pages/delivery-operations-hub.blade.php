<x-filament-hub.page-shell>
    <x-filament::section heading="مركز عمليات التوصيل" description="متابعة التوصيل عبر جميع الشركات مع التركيز على الحالات التي تحتاج تدخل الإدارة.">
        <x-filament-hub.kpi-grid columns="md:grid-cols-2 xl:grid-cols-3">
            @foreach ($overviewKpis as $metric)
                <x-filament-hub.kpi-stat
                    :label="$metric['label']"
                    :value="$metric['value']"
                    :tone="$metric['tone'] ?? 'neutral'"
                    format-value-as-integer
                />
            @endforeach
        </x-filament-hub.kpi-grid>
    </x-filament::section>

    <x-filament::section heading="الوصول السريع" description="عرض ومتابعة الطلبات والمندوبين والشركات دون تغيير سير عمل لوحة شركة التوصيل.">
        <x-filament-hub.lane-grid columns="md:grid-cols-3">
            @foreach ($workflowLinks as $link)
                <x-filament-hub.workflow-link
                    :label="$link['label']"
                    :url="$link['url']"
                    :tone="$link['tone'] ?? 'neutral'"
                    action-emphasis
                />
            @endforeach
        </x-filament-hub.lane-grid>
    </x-filament::section>

    <x-filament::section heading="تحتاج انتباه" description="الحالات التشغيلية التي يجب أن يراجعها فريق الإدارة الآن.">
        <x-filament-hub.queue-grid>
            @foreach ($attentionQueues as $queue)
                <x-filament-hub.queue-card
                    :title="$queue['title']"
                    :count="$queue['count']"
                    :items="$queue['items']"
                    :empty-message="$queue['emptyMessage']"
                />
            @endforeach
        </x-filament-hub.queue-grid>
    </x-filament::section>
</x-filament-hub.page-shell>
