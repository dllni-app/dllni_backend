<div class="space-y-6">
    @if ($alerts !== null)
        <x-filament::section heading="تنبيهات النظام المفتوحة" description="تنبيهات تحتاج استلام أو حل من فريق الإدارة.">
            <x-filament-hub.workflow-link label="فتح تنبيهات النظام" :url="$alerts['url']" :badge="(string) $alerts['count']" tone="danger" action-emphasis />
        </x-filament::section>
    @endif

    @if (empty($sections))
        <x-filament::section heading="مركز عمليات المنصة">
            <x-filament-hub.empty-state message="لا توجد أقسام تشغيلية متاحة ضمن صلاحيات هذا الحساب." />
        </x-filament::section>
    @endif

    @foreach ($sections as $section)
        <x-filament::section :heading="$section['title']" description="الحالات التشغيلية الأهم التي تحتاج متابعة الآن.">
            <x-filament-hub.kpi-grid columns="md:grid-cols-3">
                @foreach ($section['metrics'] as $metric)
                    <x-filament-hub.kpi-stat
                        :label="$metric['label']"
                        :value="$metric['value']"
                        :tone="$section['tone'] ?? 'neutral'"
                        format-value-as-integer
                    />
                @endforeach
            </x-filament-hub.kpi-grid>

            <div class="mt-5">
                <x-filament-hub.workflow-link
                    :label="'فتح قسم '.$section['title']"
                    :url="$section['url']"
                    :tone="$section['tone'] ?? 'neutral'"
                    action-emphasis
                />
            </div>

            <div class="mt-5">
                <x-filament-hub.queue-card
                    title="تحتاج انتباه"
                    :count="count($section['items'])"
                    :items="$section['items']"
                    empty-message="لا توجد حالات حرجة في هذه القائمة حالياً."
                />
            </div>
        </x-filament::section>
    @endforeach
</div>
