<x-filament-hub.page-shell>
    <x-filament::section :heading="__('cleaning_admin.pages.geographic_coverage.title')"
        :description="__('cleaning_admin.pages.geographic_coverage.description')">
        <x-filament-hub.filter-toolbar
            search-model="search"
            :search-placeholder="__('cleaning_admin.pages.geographic_coverage.search_placeholder')"
            status-model="levelFilter"
            :status-options="$filters['levels']"
            range-model="dateRange"
            :range-options="$filters['dateRange']"
        />

        <div class="mt-4 grid gap-3 md:grid-cols-2">
            <a href="{{ \App\Filament\Resources\CleaningNeighborhoods\CleaningNeighborhoodResource::getUrl() }}"
                class="rounded-xl border border-primary-200 bg-primary-50 px-4 py-3 text-center text-sm font-semibold text-primary-700 transition hover:border-primary-600 hover:shadow-sm dark:border-primary-700/60 dark:bg-primary-900/20 dark:text-primary-300">
                {{ __('cleaning_admin.shared.actions.view') }}: {{ __('cleaning_admin.cleaning_neighborhoods.nav_label') }}
            </a>
            <a href="{{ \App\Filament\Pages\CleaningOverview::getUrl() }}"
                class="rounded-xl border border-gray-200 bg-white px-4 py-3 text-center text-sm font-semibold text-gray-700 transition hover:border-gray-400 hover:shadow-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">
                {{ __('cleaning_admin.shared.actions.view') }}: {{ __('cleaning_admin.overview.title') }}
            </a>
        </div>

        <div class="mt-4 grid gap-4 md:grid-cols-2 xl:grid-cols-5">
            <x-filament-hub.kpi-stat
                :label="__('cleaning_admin.pages.geographic_coverage.summary.neighborhoods_count')"
                :value="$summary['neighborhoods_count']"
                tone="primary"
                format-value-as-integer
                card-padding="p-4"
                value-size="xl"
            />
            <x-filament-hub.kpi-stat
                :label="__('cleaning_admin.pages.geographic_coverage.summary.workers_count')"
                :value="$summary['workers_count']"
                tone="info"
                format-value-as-integer
                card-padding="p-4"
                value-size="xl"
            />
            <x-filament-hub.kpi-stat
                :label="__('cleaning_admin.pages.geographic_coverage.summary.pending_bookings_count')"
                :value="$summary['pending_bookings_count']"
                tone="warning"
                format-value-as-integer
                card-padding="p-4"
                value-size="xl"
            />
            <x-filament-hub.kpi-stat
                :label="__('cleaning_admin.pages.geographic_coverage.summary.active_bookings_count')"
                :value="$summary['active_bookings_count']"
                tone="success"
                format-value-as-integer
                card-padding="p-4"
                value-size="xl"
            />
            <x-filament-hub.kpi-stat
                :label="__('cleaning_admin.pages.geographic_coverage.summary.uncovered_count')"
                :value="$summary['uncovered_count']"
                tone="danger"
                format-value-as-integer
                card-padding="p-4"
                value-size="xl"
            />
        </div>
    </x-filament::section>

    <x-filament::section :heading="__('cleaning_admin.pages.geographic_coverage.table_title')">
        @if ($rows->isEmpty())
            <x-filament-hub.empty-state :message="__('cleaning_admin.pages.geographic_coverage.empty')" />
        @else
            <div class="overflow-x-auto rounded-xl border border-gray-200 dark:border-gray-700">
                <table class="min-w-full divide-y divide-gray-200 text-sm dark:divide-gray-700">
                    <thead class="bg-gray-50 dark:bg-gray-800/50">
                        <tr>
                            <th class="px-3 py-2 text-start font-medium text-gray-700 dark:text-gray-300">{{ __('cleaning_admin.pages.geographic_coverage.columns.neighborhood') }}</th>
                            <th class="px-3 py-2 text-start font-medium text-gray-700 dark:text-gray-300">{{ __('cleaning_admin.pages.geographic_coverage.columns.pending_bookings_count') }}</th>
                            <th class="px-3 py-2 text-start font-medium text-gray-700 dark:text-gray-300">{{ __('cleaning_admin.pages.geographic_coverage.columns.active_bookings_count') }}</th>
                            <th class="px-3 py-2 text-start font-medium text-gray-700 dark:text-gray-300">{{ __('cleaning_admin.pages.geographic_coverage.columns.workers_count') }}</th>
                            <th class="px-3 py-2 text-start font-medium text-gray-700 dark:text-gray-300">{{ __('cleaning_admin.pages.geographic_coverage.columns.coverage_ratio') }}</th>
                            <th class="px-3 py-2 text-start font-medium text-gray-700 dark:text-gray-300">{{ __('cleaning_admin.filters.status') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 bg-white dark:divide-gray-700 dark:bg-gray-900">
                        @foreach ($rows as $row)
                            <tr class="hover:bg-gray-50 dark:hover:bg-gray-800/80">
                                <td class="px-3 py-2">
                                    <div class="font-semibold text-gray-900 dark:text-gray-100">{{ $row['neighborhood'] }}</div>
                                    <div class="text-xs text-gray-500 dark:text-gray-400">{{ $row['city_name'] }}</div>
                                </td>
                                <td class="px-3 py-2 text-gray-900 dark:text-gray-100">{{ \App\Filament\Support\AdminUiFormatter::formatNumber($row['pending_bookings_count'], 0) }}</td>
                                <td class="px-3 py-2 text-gray-900 dark:text-gray-100">{{ \App\Filament\Support\AdminUiFormatter::formatNumber($row['active_bookings_count'], 0) }}</td>
                                <td class="px-3 py-2 text-gray-900 dark:text-gray-100">{{ \App\Filament\Support\AdminUiFormatter::formatNumber($row['workers_count'], 0) }}</td>
                                <td class="px-3 py-2 text-gray-900 dark:text-gray-100">{{ \App\Filament\Support\AdminUiFormatter::formatNumber($row['coverage_ratio'], 1) }}</td>
                                <td class="px-3 py-2 text-gray-900 dark:text-gray-100">
                                    <x-filament-hub.status-badge :label="$row['level_label']" :tone="$row['level_tone']" />
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>

    <x-filament::section :heading="__('cleaning_admin.pages.geographic_coverage.legacy_unmapped_title')"
        :description="__('cleaning_admin.pages.geographic_coverage.legacy_unmapped_description')">
        @if ($legacyZones->isEmpty())
            <x-filament-hub.empty-state :message="__('cleaning_admin.pages.geographic_coverage.legacy_empty')" />
        @else
            <div class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-800/70 dark:bg-amber-950/30 dark:text-amber-100">
                {{ __('cleaning_admin.pages.geographic_coverage.legacy_warning', ['count' => $summary['legacy_zones_count']]) }}
            </div>

            <div class="mt-4 overflow-x-auto rounded-xl border border-gray-200 dark:border-gray-700">
                <table class="min-w-full divide-y divide-gray-200 text-sm dark:divide-gray-700">
                    <thead class="bg-gray-50 dark:bg-gray-800/50">
                        <tr>
                            <th class="px-3 py-2 text-start font-medium text-gray-700 dark:text-gray-300">{{ __('cleaning_admin.pages.geographic_coverage.legacy_columns.name') }}</th>
                            <th class="px-3 py-2 text-start font-medium text-gray-700 dark:text-gray-300">{{ __('cleaning_admin.pages.geographic_coverage.legacy_columns.zones_count') }}</th>
                            <th class="px-3 py-2 text-start font-medium text-gray-700 dark:text-gray-300">{{ __('cleaning_admin.pages.geographic_coverage.legacy_columns.workers_count') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 bg-white dark:divide-gray-700 dark:bg-gray-900">
                        @foreach ($legacyZones as $row)
                            <tr class="hover:bg-gray-50 dark:hover:bg-gray-800/80">
                                <td class="px-3 py-2 font-semibold text-gray-900 dark:text-gray-100">{{ $row['name'] }}</td>
                                <td class="px-3 py-2 text-gray-900 dark:text-gray-100">{{ \App\Filament\Support\AdminUiFormatter::formatNumber($row['zones_count'], 0) }}</td>
                                <td class="px-3 py-2 text-gray-900 dark:text-gray-100">{{ \App\Filament\Support\AdminUiFormatter::formatNumber($row['workers_count'], 0) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>
</x-filament-hub.page-shell>
