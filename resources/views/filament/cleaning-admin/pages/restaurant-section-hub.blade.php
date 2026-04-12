<x-filament-hub.page-shell>
    <x-filament::section :heading="__('restaurant_admin.hub.kpis_heading')" :description="__('restaurant_admin.hub.kpis_description')">
        <x-filament-hub.kpi-grid columns="sm:grid-cols-2 xl:grid-cols-3">
            @foreach ($kpis as $kpi)
                <x-filament-hub.kpi-stat :label="$kpi['label']" :value="$kpi['value']" :url="$kpi['url'] ?? null"
                    :hint="$kpi['hint'] ?? null" format-value-as-integer />
            @endforeach
        </x-filament-hub.kpi-grid>
    </x-filament::section>

    <x-filament::section :heading="__('restaurant_admin.hub.priorities_heading')"
        :description="__('restaurant_admin.hub.priorities_description')">
        <x-filament-hub.lane-grid columns="xl:grid-cols-3">
            <x-filament-hub.priority-column :title="__('restaurant_admin.hub.priorities.restaurants')"
                :items="$priorityRestaurantRows" :empty-message="__('restaurant_admin.hub.empty_state')" />
            <x-filament-hub.priority-column :title="__('restaurant_admin.hub.priorities.disputes')"
                :items="$priorityDisputeRows" :empty-message="__('restaurant_admin.hub.empty_state')" />
            <x-filament-hub.priority-column id="restaurant-hub-low-stock"
                :title="__('restaurant_admin.hub.priorities.inventory')" :items="$priorityProductRows"
                :empty-message="__('restaurant_admin.hub.empty_state')" />
        </x-filament-hub.lane-grid>
    </x-filament::section>

    <x-filament::section :heading="__('restaurant_admin.hub.quick_actions.heading')"
        :description="__('restaurant_admin.hub.quick_actions.description')">
        <x-filament-hub.quick-links :links="$quickActions" columns="grid gap-4 md:grid-cols-2 xl:grid-cols-4"
            :centered="true" />
    </x-filament::section>

    <x-filament::section :heading="__('restaurant_admin.hub.checklist.heading')"
        :description="__('restaurant_admin.hub.checklist.description')">
        <x-filament-hub.kpi-grid columns="md:grid-cols-2 xl:grid-cols-3">
            @foreach ($checklist as $item)
                <x-filament-hub.kpi-stat :label="$item['label']" :value="$item['value']" :url="$item['url']"
                    value-size="xl" card-padding="p-4" format-value-as-integer />
            @endforeach
        </x-filament-hub.kpi-grid>
    </x-filament::section>
</x-filament-hub.page-shell>
