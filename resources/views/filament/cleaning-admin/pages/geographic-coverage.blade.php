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

        <div class="mt-4 grid gap-3 md:grid-cols-3">
            <a href="{{ \App\Filament\Pages\CleaningOverview::getUrl() }}"
                class="rounded-xl border border-primary-200 bg-primary-50 px-4 py-3 text-center text-sm font-semibold text-primary-700 transition hover:border-primary-600 hover:shadow-sm dark:border-primary-700/60 dark:bg-primary-900/20 dark:text-primary-300">
                {{ __('cleaning_admin.shared.actions.view') }}: {{ __('cleaning_admin.overview.title') }}
            </a>
        </div>

        <div class="mt-4 grid gap-4 md:grid-cols-3">
            <x-filament-hub.kpi-stat
                :label="__('cleaning_admin.pages.geographic_coverage.summary.workers_count')"
                :value="$summary['workers_count']"
                tone="primary"
                format-value-as-integer
                card-padding="p-4"
                value-size="xl"
            />
            <x-filament-hub.kpi-stat
                :label="__('cleaning_admin.pages.geographic_coverage.summary.high_pressure_count')"
                :value="$summary['high_pressure_count']"
                tone="danger"
                format-value-as-integer
                card-padding="p-4"
                value-size="xl"
            />
            <x-filament-hub.kpi-stat
                :label="__('cleaning_admin.pages.geographic_coverage.summary.regions_count')"
                :value="$summary['regions_count']"
                tone="info"
                format-value-as-integer
                card-padding="p-4"
                value-size="xl"
            />
        </div>
    </x-filament::section>

    <div class="grid gap-6 xl:grid-cols-2">
        <x-filament::section :heading="__('cleaning_admin.pages.geographic_coverage.table_title')">
            @if ($rows->isEmpty())
                <x-filament-hub.empty-state :message="__('cleaning_admin.pages.geographic_coverage.empty')" />
            @else
                <div class="overflow-x-auto rounded-xl border border-gray-200 dark:border-gray-700">
                    <table class="min-w-full divide-y divide-gray-200 text-sm dark:divide-gray-700">
                        <thead class="bg-gray-50 dark:bg-gray-800/50">
                            <tr>
                                <th class="px-3 py-2 text-start font-medium text-gray-700 dark:text-gray-300">{{ __('cleaning_admin.pages.geographic_coverage.columns.zone') }}</th>
                                <th class="px-3 py-2 text-start font-medium text-gray-700 dark:text-gray-300">{{ __('cleaning_admin.pages.geographic_coverage.columns.demand_count') }}</th>
                                <th class="px-3 py-2 text-start font-medium text-gray-700 dark:text-gray-300">{{ __('cleaning_admin.pages.geographic_coverage.columns.workers_count') }}</th>
                                <th class="px-3 py-2 text-start font-medium text-gray-700 dark:text-gray-300">{{ __('cleaning_admin.pages.geographic_coverage.columns.coverage_ratio') }}</th>
                                <th class="px-3 py-2 text-start font-medium text-gray-700 dark:text-gray-300">{{ __('cleaning_admin.filters.status') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 bg-white dark:divide-gray-700 dark:bg-gray-900">
                            @foreach ($rows as $row)
                                <tr class="hover:bg-gray-50 dark:hover:bg-gray-800/80">
                                    <td class="px-3 py-2 font-semibold text-gray-900 dark:text-gray-100">{{ $row['zone'] }}</td>
                                    <td class="px-3 py-2 text-gray-900 dark:text-gray-100">{{ \App\Filament\Support\AdminUiFormatter::formatNumber($row['demand_count'], 0) }}</td>
                                    <td class="px-3 py-2 text-gray-900 dark:text-gray-100">{{ \App\Filament\Support\AdminUiFormatter::formatNumber($row['workers_count'], 0) }}</td>
                                    <td class="px-3 py-2 text-gray-900 dark:text-gray-100">{{ \App\Filament\Support\AdminUiFormatter::formatNumber($row['coverage_ratio'], 0) }}</td>
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

        <x-filament::section :heading="__('cleaning_admin.pages.geographic_coverage.chart_title')">
            @if ($rows->isEmpty())
                <x-filament-hub.empty-state :message="__('cleaning_admin.pages.geographic_coverage.empty')" />
            @else
                @php
                    $maxValue = max(1, (int) $rows->max('demand_count'), (int) $rows->max('workers_count'));
                @endphp
                <div class="space-y-3">
                    @foreach ($rows as $row)
                        @php
                            $demandWidth = round(($row['demand_count'] / $maxValue) * 100, 1);
                            $workersWidth = round(($row['workers_count'] / $maxValue) * 100, 1);
                        @endphp
                        <div class="rounded-xl border border-gray-200 bg-white p-3 dark:border-gray-700 dark:bg-gray-900">
                            <div class="mb-2 flex items-center justify-between gap-2">
                                <p class="text-sm font-semibold text-gray-900 dark:text-gray-100">{{ $row['zone'] }}</p>
                                <x-filament-hub.status-badge :label="$row['level_label']" :tone="$row['level_tone']" />
                            </div>
                            <div class="space-y-2 text-xs text-gray-600 dark:text-gray-300">
                                <div>
                                    <div class="mb-1 flex items-center justify-between">
                                        <span>{{ __('cleaning_admin.pages.geographic_coverage.legends.demand') }}</span>
                                        <span>{{ \App\Filament\Support\AdminUiFormatter::formatNumber($row['demand_count'], 0) }}</span>
                                    </div>
                                    <div class="h-2 overflow-hidden rounded-full bg-gray-200 dark:bg-gray-700">
                                        <div class="h-full rounded-full bg-blue-500" style="width: {{ $demandWidth }}%;"></div>
                                    </div>
                                </div>
                                <div>
                                    <div class="mb-1 flex items-center justify-between">
                                        <span>{{ __('cleaning_admin.pages.geographic_coverage.legends.workers') }}</span>
                                        <span>{{ \App\Filament\Support\AdminUiFormatter::formatNumber($row['workers_count'], 0) }}</span>
                                    </div>
                                    <div class="h-2 overflow-hidden rounded-full bg-gray-200 dark:bg-gray-700">
                                        <div class="h-full rounded-full bg-amber-500" style="width: {{ $workersWidth }}%;"></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </x-filament::section>
    </div>
</x-filament-hub.page-shell>
