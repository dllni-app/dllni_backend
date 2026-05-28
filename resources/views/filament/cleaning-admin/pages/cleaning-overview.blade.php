<x-filament-hub.page-shell>
    @once
        <style>
            .co-page {
                direction: rtl;
                display: grid;
                gap: 1.25rem;
            }

            .co-card {
                background: rgb(255 255 255);
                border: 1px solid rgb(229 231 235);
                border-radius: 1rem;
                box-shadow: 0 1px 2px rgb(15 23 42 / 0.05);
            }

            .dark .co-card {
                background: rgb(17 24 39);
                border-color: rgb(31 41 55);
            }

            .co-hero {
                display: grid;
                grid-template-columns: minmax(0, 1.25fr) minmax(280px, 0.75fr);
                overflow: hidden;
            }

            .co-hero-main,
            .co-focus {
                padding: 1.5rem;
            }

            .co-focus {
                background: rgb(249 250 251);
                border-right: 1px solid rgb(229 231 235);
            }

            .dark .co-focus {
                background: rgb(3 7 18);
                border-color: rgb(31 41 55);
            }

            .co-row {
                display: flex;
                align-items: center;
                justify-content: space-between;
                gap: 1rem;
            }

            .co-wrap {
                display: flex;
                flex-wrap: wrap;
                gap: .5rem;
            }

            .co-title {
                margin: 0;
                color: rgb(3 7 18);
                font-size: 1.875rem;
                font-weight: 800;
                line-height: 1.25;
            }

            .dark .co-title,
            .dark .co-heading,
            .dark .co-value {
                color: rgb(255 255 255);
            }

            .co-muted {
                color: rgb(75 85 99);
                font-size: .875rem;
                line-height: 1.7;
            }

            .dark .co-muted {
                color: rgb(156 163 175);
            }

            .co-badge {
                display: inline-flex;
                align-items: center;
                gap: .5rem;
                border-radius: 999px;
                background: rgb(240 253 244);
                color: rgb(21 128 61);
                font-size: .8125rem;
                font-weight: 700;
                padding: .45rem .75rem;
            }

            .co-dot {
                width: .55rem;
                height: .55rem;
                border-radius: 999px;
                background: rgb(34 197 94);
            }

            .co-actions,
            .co-kpis,
            .co-focus-list {
                display: grid;
                gap: .75rem;
            }

            .co-actions {
                grid-template-columns: repeat(3, minmax(0, 1fr));
                margin-top: 1.25rem;
            }

            .co-action,
            .co-kpi,
            .co-focus-item,
            .co-trend-row {
                border: 1px solid rgb(229 231 235);
                border-radius: .875rem;
                background: rgb(249 250 251);
                padding: 1rem;
            }

            .dark .co-action,
            .dark .co-kpi,
            .dark .co-focus-item,
            .dark .co-trend-row {
                background: rgb(17 24 39);
                border-color: rgb(31 41 55);
            }

            .co-action {
                display: block;
                color: inherit;
                text-decoration: none;
                transition: transform .15s ease, border-color .15s ease, box-shadow .15s ease;
            }

            .co-action:hover,
            .co-kpi:hover {
                border-color: rgb(217 119 6);
                box-shadow: 0 8px 20px rgb(15 23 42 / 0.08);
                transform: translateY(-1px);
            }

            .co-icon {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                width: 2.35rem;
                height: 2.35rem;
                border-radius: .75rem;
                color: white;
                background: rgb(217 119 6);
            }

            .co-heading {
                margin: .75rem 0 .25rem;
                color: rgb(17 24 39);
                font-size: .925rem;
                font-weight: 800;
            }

            .co-small {
                margin: 0;
                color: rgb(107 114 128);
                font-size: .78rem;
                line-height: 1.6;
            }

            .co-kpis {
                grid-template-columns: repeat(5, minmax(0, 1fr));
            }

            .co-kpi {
                display: block;
                color: inherit;
                text-decoration: none;
                transition: transform .15s ease, border-color .15s ease, box-shadow .15s ease;
            }

            .co-kpi-top {
                display: flex;
                align-items: flex-start;
                justify-content: space-between;
                gap: .75rem;
            }

            .co-value {
                color: rgb(3 7 18);
                font-size: 2rem;
                font-weight: 900;
                line-height: 1;
                margin-top: .9rem;
            }

            .co-tone-primary .co-icon,
            .co-tone-primary .co-bar-fill {
                background: rgb(37 99 235);
            }

            .co-tone-success .co-icon,
            .co-tone-success .co-bar-fill {
                background: rgb(22 163 74);
            }

            .co-tone-warning .co-icon,
            .co-tone-warning .co-bar-fill {
                background: rgb(217 119 6);
            }

            .co-tone-danger .co-icon,
            .co-tone-danger .co-bar-fill {
                background: rgb(220 38 38);
            }

            .co-tone-info .co-icon,
            .co-tone-info .co-bar-fill {
                background: rgb(8 145 178);
            }

            .co-two-col {
                display: grid;
                grid-template-columns: minmax(0, 1.1fr) minmax(320px, .9fr);
                gap: 1.25rem;
            }

            .co-section {
                padding: 1.25rem;
            }

            .co-section-head {
                display: flex;
                align-items: flex-start;
                justify-content: space-between;
                gap: 1rem;
                margin-bottom: 1rem;
            }

            .co-section-title {
                margin: 0;
                color: rgb(17 24 39);
                font-size: 1rem;
                font-weight: 800;
            }

            .dark .co-section-title {
                color: rgb(255 255 255);
            }

            .co-count {
                border-radius: 999px;
                background: rgb(254 243 199);
                color: rgb(180 83 9);
                font-size: .78rem;
                font-weight: 800;
                padding: .3rem .7rem;
            }

            .co-stack {
                display: grid;
                gap: .85rem;
            }

            .co-trend-row {
                display: grid;
                grid-template-columns: 6rem minmax(0, 1fr);
                align-items: center;
                gap: 1rem;
            }

            .co-bars {
                display: grid;
                gap: .55rem;
            }

            .co-bar-line {
                display: grid;
                grid-template-columns: 5rem minmax(0, 1fr) 2.25rem;
                align-items: center;
                gap: .5rem;
                color: rgb(75 85 99);
                font-size: .78rem;
            }

            .co-bar {
                height: .55rem;
                overflow: hidden;
                border-radius: 999px;
                background: rgb(229 231 235);
            }

            .dark .co-bar {
                background: rgb(31 41 55);
            }

            .co-bar-fill {
                height: 100%;
                border-radius: inherit;
            }

            .co-sos {
                border-color: rgb(254 202 202);
                background: rgb(254 242 242);
            }

            .dark .co-sos {
                border-color: rgb(127 29 29);
                background: rgb(69 10 10 / .45);
            }

            .co-empty {
                border: 1px dashed rgb(209 213 219);
                border-radius: 1rem;
                padding: 2rem;
                text-align: center;
            }

            @media (max-width: 1180px) {
                .co-hero,
                .co-two-col {
                    grid-template-columns: 1fr;
                }

                .co-focus {
                    border-right: 0;
                    border-top: 1px solid rgb(229 231 235);
                }

                .co-kpis {
                    grid-template-columns: repeat(2, minmax(0, 1fr));
                }
            }

            @media (max-width: 760px) {
                .co-actions,
                .co-kpis {
                    grid-template-columns: 1fr;
                }

                .co-hero-main,
                .co-focus,
                .co-section {
                    padding: 1rem;
                }

                .co-title {
                    font-size: 1.45rem;
                }

                .co-trend-row {
                    grid-template-columns: 1fr;
                }
            }
        </style>
    @endonce

    <div class="co-page">
        <section class="co-card co-hero">
            <div class="co-hero-main">
                <div class="co-wrap">
                    <span class="co-badge">
                        <span class="co-dot"></span>
                        {{ __('cleaning_admin.overview.live_now') }}
                    </span>
                    <span class="co-badge" style="background: rgb(243 244 246); color: rgb(55 65 81);">
                        <x-filament::icon icon="heroicon-o-clock" style="width: 1rem; height: 1rem;" />
                        {{ now()->format('Y-m-d H:i') }}
                    </span>
                </div>

                <h1 class="co-title" style="margin-top: 1rem;">{{ __('cleaning_admin.overview.title') }}</h1>
                <p class="co-muted" style="max-width: 46rem;">{{ __('cleaning_admin.overview.operator_brief') }}</p>

                <div class="co-actions">
                    @foreach ($quickActions as $action)
                        <a href="{{ $action['url'] }}" class="co-action">
                            <span class="co-icon">
                                <x-filament::icon :icon="$action['icon']" style="width: 1.2rem; height: 1.2rem;" />
                            </span>
                            <p class="co-heading">{{ $action['label'] }}</p>
                            <p class="co-small">{{ $action['description'] }}</p>
                        </a>
                    @endforeach
                </div>
            </div>

            <aside class="co-focus">
                <div class="co-row">
                    <div>
                        <p class="co-section-title">{{ __('cleaning_admin.overview.today_focus') }}</p>
                        <p class="co-small" style="margin-top: .25rem;">{{ __('cleaning_admin.overview.today_focus_hint') }}</p>
                    </div>
                    <span class="co-icon">
                        <x-filament::icon icon="heroicon-o-squares-2x2" style="width: 1.15rem; height: 1.15rem;" />
                    </span>
                </div>

                <div class="co-focus-list" style="margin-top: 1rem;">
                    @foreach ($workloadSummary as $item)
                        <div class="co-focus-item co-tone-{{ $item['tone'] }}">
                            <div class="co-row">
                                <div>
                                    <p class="co-heading" style="margin-top: 0;">{{ $item['label'] }}</p>
                                    <p class="co-small">{{ $item['description'] }}</p>
                                </div>
                                <strong class="co-value" style="font-size: 1.7rem; margin-top: 0;">{{ $item['value'] }}</strong>
                            </div>
                        </div>
                    @endforeach
                </div>
            </aside>
        </section>

        <section class="co-kpis">
            @foreach ($overviewKpis as $kpi)
                <a href="{{ $kpi['url'] ?? '#' }}" class="co-kpi co-tone-{{ $kpi['tone'] }}">
                    <div class="co-kpi-top">
                        <div>
                            <p class="co-heading" style="margin-top: 0;">{{ $kpi['label'] }}</p>
                            <p class="co-value">{{ $kpi['value'] }}</p>
                        </div>
                        <span class="co-icon">
                            <x-filament::icon :icon="$kpi['icon']" style="width: 1.25rem; height: 1.25rem;" />
                        </span>
                    </div>
                    <p class="co-small" style="margin-top: .75rem;">{{ $kpi['hint'] }}</p>
                </a>
            @endforeach
        </section>

        <section class="co-two-col">
            <div class="co-card co-section">
                <div class="co-section-head">
                    <div>
                        <h2 class="co-section-title">{{ __('cleaning_admin.overview.sections.activity_trend') }}</h2>
                        <p class="co-small">{{ __('cleaning_admin.overview.sections.activity_trend_hint') }}</p>
                    </div>
                </div>

                <div class="co-stack">
                    @foreach ($activityTrend['days'] as $day)
                        @php
                            $bookingWidth = round(($day['bookings'] / max(1, $activityTrend['max'])) * 100, 1);
                            $alertWidth = round(($day['alerts'] / max(1, $activityTrend['max'])) * 100, 1);
                        @endphp
                        <div class="co-trend-row">
                            <div>
                                <p class="co-heading" style="margin: 0;">{{ $day['label'] }}</p>
                                <p class="co-small">{{ $day['date'] }}</p>
                            </div>
                            <div class="co-bars">
                                <div class="co-bar-line co-tone-primary">
                                    <span>{{ __('cleaning_admin.overview.labels.bookings') }}</span>
                                    <span class="co-bar"><span class="co-bar-fill" style="width: {{ $bookingWidth }}%;"></span></span>
                                    <strong>{{ $day['bookings'] }}</strong>
                                </div>
                                <div class="co-bar-line co-tone-danger">
                                    <span>{{ __('cleaning_admin.overview.labels.alerts') }}</span>
                                    <span class="co-bar"><span class="co-bar-fill" style="width: {{ $alertWidth }}%;"></span></span>
                                    <strong>{{ $day['alerts'] }}</strong>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>

            <div class="co-stack">
                <div class="co-card co-section">
                    <div class="co-section-head">
                        <div>
                            <h2 class="co-section-title">{{ __('cleaning_admin.overview.sections.booking_statuses') }}</h2>
                            <p class="co-small">{{ __('cleaning_admin.overview.sections.booking_statuses_hint') }}</p>
                        </div>
                        <span class="co-count">{{ $cleaningStatusBreakdown['total'] }}</span>
                    </div>

                    <div class="co-stack">
                        @forelse ($cleaningStatusBreakdown['items'] as $item)
                            <div class="co-tone-primary">
                                <div class="co-row">
                                    <span class="co-heading" style="margin: 0;">{{ $item['label'] }}</span>
                                    <strong>{{ $item['value'] }} · {{ $item['share'] }}%</strong>
                                </div>
                                <div class="co-bar" style="margin-top: .5rem;">
                                    <span class="co-bar-fill" style="width: {{ $item['width'] }}%;"></span>
                                </div>
                            </div>
                        @empty
                            <div class="co-empty co-muted">{{ __('cleaning_admin.overview.empty.booking_statuses') }}</div>
                        @endforelse
                    </div>
                </div>

                <div class="co-card co-section">
                    <div class="co-section-head">
                        <div>
                            <h2 class="co-section-title">{{ __('cleaning_admin.overview.sections.alert_types') }}</h2>
                            <p class="co-small">{{ __('cleaning_admin.overview.sections.alert_types_hint') }}</p>
                        </div>
                        <span class="co-count">{{ $alertTypeBreakdown['total'] }}</span>
                    </div>

                    <div class="co-stack">
                        @forelse ($alertTypeBreakdown['items'] as $item)
                            <div class="co-tone-warning">
                                <div class="co-row">
                                    <span class="co-heading" style="margin: 0;">{{ $item['label'] }}</span>
                                    <strong>{{ $item['value'] }}</strong>
                                </div>
                                <div class="co-bar" style="margin-top: .5rem;">
                                    <span class="co-bar-fill" style="width: {{ $item['width'] }}%;"></span>
                                </div>
                            </div>
                        @empty
                            <div class="co-empty co-muted">{{ __('cleaning_admin.overview.empty.alert_types') }}</div>
                        @endforelse
                    </div>
                </div>
            </div>
        </section>

        <section class="co-two-col" style="grid-template-columns: minmax(280px, .8fr) minmax(0, 1.2fr);">
            <div class="co-card co-section">
                <div class="co-section-head">
                    <div>
                        <h2 class="co-section-title">{{ __('cleaning_admin.overview.sections.alert_lifecycle') }}</h2>
                        <p class="co-small">{{ __('cleaning_admin.overview.sections.alert_lifecycle_hint') }}</p>
                    </div>
                    <span class="co-count">{{ $systemAlertStatusBreakdown['total'] }}</span>
                </div>

                <div class="co-stack">
                    @forelse ($systemAlertStatusBreakdown['items'] as $item)
                        <div class="co-tone-info">
                            <div class="co-row">
                                <span class="co-heading" style="margin: 0;">{{ $item['label'] }}</span>
                                <strong>{{ $item['value'] }}</strong>
                            </div>
                            <div class="co-bar" style="margin-top: .5rem;">
                                <span class="co-bar-fill" style="width: {{ $item['width'] }}%;"></span>
                            </div>
                        </div>
                    @empty
                        <div class="co-empty co-muted">{{ __('cleaning_admin.overview.empty.alert_lifecycle') }}</div>
                    @endforelse
                </div>
            </div>

            <div class="co-card co-section">
                <div class="co-section-head">
                    <div>
                        <h2 class="co-section-title">{{ __('cleaning_admin.overview.alerts.system_heading') }}</h2>
                        <p class="co-small">{{ __('cleaning_admin.overview.sections.alert_queue_hint') }}</p>
                    </div>
                    <span class="co-count">{{ count($otherAlerts) }}</span>
                </div>

                <div class="co-stack">
                    @forelse ($otherAlerts as $alert)
                        @include('filament-hub.partials.command-alert-card', [
                            'alert' => $alert,
                            'variant' => 'primary',
                        ])
                    @empty
                        @if (count($sosAlerts) === 0)
                            <div class="co-empty">
                                <h3 class="co-section-title">{{ __('cleaning_admin.overview.alerts.none') }}</h3>
                                <p class="co-small">{{ __('cleaning_admin.overview.empty.all_clear') }}</p>
                            </div>
                        @endif
                    @endforelse
                </div>
            </div>
        </section>

        @if (count($sosAlerts) > 0)
            <section class="co-card co-section co-sos">
                <div class="co-section-head">
                    <div>
                        <h2 class="co-section-title">{{ __('cleaning_admin.overview.alerts.sos_heading') }}</h2>
                        <p class="co-small">{{ __('cleaning_admin.overview.sections.sos_hint') }}</p>
                    </div>
                    <span class="co-count" style="background: rgb(220 38 38); color: white;">{{ count($sosAlerts) }}</span>
                </div>

                <div class="co-stack">
                    @foreach ($sosAlerts as $alert)
                        @include('filament-hub.partials.command-alert-card', [
                            'alert' => $alert,
                            'variant' => 'danger',
                        ])
                    @endforeach
                </div>
            </section>
        @endif
    </div>
</x-filament-hub.page-shell>
