@php
    $chartRows = $rows->values()->all();
@endphp

<x-filament-panels::page
    x-data="geographicCoverageDashboard(@js($chartRows))"
    class="gc-page-root"
>
    <section dir="rtl" class="gc-dashboard space-y-6">
        <header class="gc-page-header">
            <h1 class="gc-page-title">التغطية حسب المنطقة</h1>
            <p class="gc-page-subtitle">
                عرض الطلب مقابل تغطية العمال حسب المناطق الجغرافية لتحديد فجوات الخدمة (منخفض / جيد / مرتفع) وعدد
                العمال لكل منطقة.
            </p>
        </header>

        <div class="grid gap-4 md:grid-cols-3">
            <article class="gc-stat-card">
                <div class="gc-stat-content">
                    <p class="gc-stat-label">العمال المتاحون</p>
                    <p class="gc-stat-value">{{ $summary['workers_count'] }}</p>
                </div>
                <div class="gc-stat-icon gc-stat-icon-blue">
                    <x-filament::icon icon="heroicon-o-user-group" class="h-7 w-7" />
                </div>
            </article>

            <article class="gc-stat-card">
                <div class="gc-stat-content">
                    <p class="gc-stat-label">مناطق ذات طلب مرتفع</p>
                    <p class="gc-stat-value gc-stat-value-danger">{{ $summary['high_pressure_count'] }}</p>
                </div>
                <div class="gc-stat-icon gc-stat-icon-red">
                    <x-filament::icon icon="heroicon-o-exclamation-triangle" class="h-7 w-7" />
                </div>
            </article>

            <article class="gc-stat-card">
                <div class="gc-stat-content">
                    <p class="gc-stat-label">إجمالي المناطق</p>
                    <p class="gc-stat-value">{{ $summary['regions_count'] }}</p>
                </div>
                <div class="gc-stat-icon gc-stat-icon-blue">
                    <x-filament::icon icon="heroicon-o-map" class="h-7 w-7" />
                </div>
            </article>
        </div>

        <section class="gc-filter-card">
            <div class="gc-filter-layout">
                <div class="gc-filter-spacer"></div>

                <div class="gc-filter-controls">
                    <div class="gc-filter-input-wrapper">
                        <label class="gc-filter-floating-label">نطاق التاريخ</label>
                        <select x-model="dateRange" class="gc-filter-select">
                            <option value="last_7_days">آخر 7 أيام</option>
                            <option value="last_14_days">آخر 14 يوم</option>
                            <option value="last_30_days">آخر 30 يوم</option>
                        </select>
                    </div>

                    <div class="gc-filter-input-wrapper">
                        <select x-model="selectedCategory" class="gc-filter-select">
                            <option value="all">فئة المنطقة</option>
                            <option value="High">ضغط مرتفع</option>
                            <option value="OK">جيد</option>
                            <option value="Low">ضغط منخفض</option>
                        </select>
                    </div>
                </div>
            </div>
        </section>

        <div class="grid gap-6 xl:grid-cols-2">
            <section class="gc-panel">
                <div class="gc-panel-header">
                    <h2 class="gc-panel-title">جدول التغطية</h2>
                </div>

                <div class="gc-table-wrap">
                    <table class="gc-table">
                        <thead>
                            <tr>
                                <th>المنطقة</th>
                                <th>الطلب المتوقع</th>
                                <th>عدد العمال</th>
                                <th>نسبة الضغط/الحالة</th>
                            </tr>
                        </thead>
                        <tbody>
                            <template x-if="filteredRows.length === 0">
                                <tr>
                                    <td colspan="4" class="gc-empty-state">لا توجد بيانات مطابقة للفلاتر الحالية.</td>
                                </tr>
                            </template>

                            <template x-for="(row, index) in filteredRows" :key="`${row.zone}-${index}`">
                                <tr>
                                    <td class="gc-zone-cell" x-text="row.zone"></td>
                                    <td x-text="row.demand_count"></td>
                                    <td x-text="row.workers_count"></td>
                                    <td>
                                        <span
                                            class="gc-status-badge"
                                            :class="statusClass(row.level)"
                                            x-text="row.level"
                                        ></span>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="gc-panel">
                <div class="gc-panel-header">
                    <h2 class="gc-panel-title">مقارنة الطلب مقابل العمال</h2>
                </div>

                <div class="gc-chart-wrap">
                    <div class="gc-chart-legend">
                        <span class="gc-chart-legend-item">
                            <span class="gc-chart-legend-swatch gc-chart-legend-swatch-demand"></span>
                            الطلب
                        </span>
                        <span class="gc-chart-legend-item">
                            <span class="gc-chart-legend-swatch gc-chart-legend-swatch-workers"></span>
                            العمال
                        </span>
                    </div>

                    <div class="gc-chart-shell">
                        <div class="gc-chart-y-axis">
                            <template x-for="tick in yTicks" :key="`tick-${tick}`">
                                <span class="gc-chart-y-tick" x-text="tick"></span>
                            </template>
                        </div>

                        <div class="gc-chart-plot-area">
                            <div class="gc-chart-grid">
                                <template x-for="tick in yTicks" :key="`line-${tick}`">
                                    <div class="gc-chart-grid-line"></div>
                                </template>
                            </div>

                            <div class="gc-chart-bars">
                                <template x-for="(row, index) in filteredRows" :key="`bar-${row.zone}-${index}`">
                                    <div class="gc-chart-group">
                                        <div class="gc-chart-columns">
                                            <div
                                                class="gc-chart-bar gc-chart-bar-demand"
                                                :style="`height: ${barHeight(row.demand_count)}%`"
                                                :title="`الطلب: ${row.demand_count}`"
                                            ></div>
                                            <div
                                                class="gc-chart-bar gc-chart-bar-workers"
                                                :style="`height: ${barHeight(row.workers_count)}%`"
                                                :title="`العمال: ${row.workers_count}`"
                                            ></div>
                                        </div>

                                        <div class="gc-chart-zone" x-text="row.zone"></div>
                                    </div>
                                </template>
                            </div>
                        </div>
                    </div>
                </div>
            </section>
        </div>
    </section>

    @script
        <script>
            if (! window.geographicCoverageDashboard) {
                window.geographicCoverageDashboard = function (rows) {
                    return {
                        rows,
                        selectedCategory: 'all',
                        dateRange: 'last_7_days',
                        get filteredRows() {
                            if (this.selectedCategory === 'all') {
                                return this.rows;
                            }

                            return this.rows.filter((row) => row.level === this.selectedCategory);
                        },
                        get chartMax() {
                            const values = this.filteredRows.flatMap((row) => [
                                Number(row.demand_count) || 0,
                                Number(row.workers_count) || 0,
                            ]);
                            const currentMax = values.length ? Math.max(...values) : 0;

                            if (currentMax <= 10) {
                                return 10;
                            }

                            return Math.ceil(currentMax / 5) * 5;
                        },
                        get yTicks() {
                            const ticks = [];
                            const step = Math.max(1, Math.ceil(this.chartMax / 5));

                            for (let value = this.chartMax; value >= 0; value -= step) {
                                ticks.push(value);
                            }

                            if (ticks[ticks.length - 1] !== 0) {
                                ticks.push(0);
                            }

                            return ticks;
                        },
                        barHeight(value) {
                            if (this.chartMax <= 0) {
                                return 0;
                            }

                            return Math.max(2, Math.round(((Number(value) || 0) / this.chartMax) * 100));
                        },
                        statusClass(level) {
                            if (level === 'High') {
                                return 'gc-status-badge-high';
                            }

                            if (level === 'Low') {
                                return 'gc-status-badge-low';
                            }

                            return 'gc-status-badge-ok';
                        },
                    };
                };
            }
        </script>
    @endscript
</x-filament-panels::page>
