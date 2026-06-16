<x-filament-hub.page-shell>
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

