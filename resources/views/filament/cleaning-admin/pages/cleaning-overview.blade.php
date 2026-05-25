<x-filament-hub.page-shell>
    <div dir="rtl" class="space-y-6">
        <div class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm dark:border-gray-800 dark:bg-gray-900">
            <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                <div>
                    <h1 class="text-2xl font-bold tracking-tight text-gray-950 dark:text-white">
                        {{ __('cleaning_admin.overview.title') }}
                    </h1>

                    <p class="mt-2 max-w-3xl text-sm leading-6 text-gray-600 dark:text-gray-400">
                        {{ __('cleaning_admin.overview.subheading') }}
                    </p>
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    <span class="inline-flex items-center gap-2 rounded-full bg-success-50 px-3 py-1.5 text-sm font-medium text-success-700 dark:bg-success-950 dark:text-success-300">
                        <span class="h-2 w-2 rounded-full bg-success-500"></span>
                        {{ __('Live now') }}
                    </span>

                    <span class="inline-flex items-center gap-2 rounded-full bg-gray-100 px-3 py-1.5 text-xs font-medium text-gray-700 dark:bg-gray-800 dark:text-gray-300">
                        <x-filament::icon icon="heroicon-o-clock" class="h-4 w-4" />
                        {{ now()->format('Y-m-d H:i') }}
                    </span>
                </div>
            </div>
        </div>

        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
            @foreach ($overviewKpis as $index => $kpi)
                @php
                    $icons = [
                        0 => 'heroicon-o-calendar-days',
                        1 => 'heroicon-o-sparkles',
                        2 => 'heroicon-o-exclamation-circle',
                        3 => 'heroicon-o-phone',
                        4 => 'heroicon-o-bell-alert',
                    ];

                    $styles = [
                        0 => [
                            'card' => 'bg-primary-50 dark:bg-primary-950/40 border-primary-100 dark:border-primary-900',
                            'icon' => 'bg-primary-100 dark:bg-primary-900 text-primary-700 dark:text-primary-300',
                            'value' => 'text-primary-700 dark:text-primary-300',
                        ],
                        1 => [
                            'card' => 'bg-success-50 dark:bg-success-950/40 border-success-100 dark:border-success-900',
                            'icon' => 'bg-success-100 dark:bg-success-900 text-success-700 dark:text-success-300',
                            'value' => 'text-success-700 dark:text-success-300',
                        ],
                        2 => [
                            'card' => 'bg-warning-50 dark:bg-warning-950/40 border-warning-100 dark:border-warning-900',
                            'icon' => 'bg-warning-100 dark:bg-warning-900 text-warning-700 dark:text-warning-300',
                            'value' => 'text-warning-700 dark:text-warning-300',
                        ],
                        3 => [
                            'card' => 'bg-danger-50 dark:bg-danger-950/40 border-danger-100 dark:border-danger-900',
                            'icon' => 'bg-danger-100 dark:bg-danger-900 text-danger-700 dark:text-danger-300',
                            'value' => 'text-danger-700 dark:text-danger-300',
                        ],
                        4 => [
                            'card' => 'bg-info-50 dark:bg-info-950/40 border-info-100 dark:border-info-900',
                            'icon' => 'bg-info-100 dark:bg-info-900 text-info-700 dark:text-info-300',
                            'value' => 'text-info-700 dark:text-info-300',
                        ],
                    ];
                @endphp

                <div class="rounded-2xl border p-5 shadow-sm transition hover:-translate-y-0.5 hover:shadow-md {{ $styles[$index]['card'] }}">
                    <div class="flex items-start justify-between gap-4">
                        <div class="min-w-0">
                            <p class="text-sm font-medium text-gray-600 dark:text-gray-400">
                                {{ $kpi['label'] }}
                            </p>

                            <p class="mt-3 text-3xl font-bold {{ $styles[$index]['value'] }}">
                                {{ $kpi['value'] }}
                            </p>

                            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                {{ __('Total records') }}
                            </p>
                        </div>

                        <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl {{ $styles[$index]['icon'] }}">
                            <x-filament::icon
                                :icon="$icons[$index]"
                                class="h-6 w-6"
                            />
                        </div>
                    </div>
                </div>
            @endforeach
        </div>

        <div class="grid gap-6 xl:grid-cols-2">
            <x-filament::section class="rounded-2xl shadow-sm">
                <x-slot name="heading">
                    <div class="flex items-center justify-between gap-3">
                        <div class="flex items-center gap-2">
                            <span class="flex h-9 w-9 items-center justify-center rounded-xl bg-primary-100 text-primary-700 dark:bg-primary-900 dark:text-primary-300">
                                <x-filament::icon icon="heroicon-o-chart-bar" class="h-5 w-5" />
                            </span>
                            <div>
                                <p class="font-bold text-gray-950 dark:text-white">{{ __('7-day activity trend') }}</p>
                                <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('Bookings vs system alerts') }}</p>
                            </div>
                        </div>
                    </div>
                </x-slot>

                <div class="space-y-3">
                    @foreach ($activityTrend['days'] as $day)
                        @php
                            $bookingWidth = round(($day['bookings'] / max(1, $activityTrend['max'])) * 100, 1);
                            $alertWidth = round(($day['alerts'] / max(1, $activityTrend['max'])) * 100, 1);
                        @endphp
                        <div class="rounded-xl border border-gray-200 p-3 dark:border-gray-800">
                            <div class="mb-2 flex items-center justify-between text-xs">
                                <span class="font-semibold text-gray-700 dark:text-gray-300">{{ $day['label'] }}</span>
                                <span class="text-gray-500 dark:text-gray-400">{{ $day['date'] }}</span>
                            </div>
                            <div class="space-y-2">
                                <div>
                                    <div class="mb-1 flex items-center justify-between text-xs text-gray-600 dark:text-gray-400">
                                        <span>{{ __('Bookings') }}</span>
                                        <span>{{ $day['bookings'] }}</span>
                                    </div>
                                    <div class="h-2 rounded-full bg-gray-200 dark:bg-gray-800">
                                        <div class="h-2 rounded-full bg-primary-500" style="width: {{ $bookingWidth }}%"></div>
                                    </div>
                                </div>
                                <div>
                                    <div class="mb-1 flex items-center justify-between text-xs text-gray-600 dark:text-gray-400">
                                        <span>{{ __('Alerts') }}</span>
                                        <span>{{ $day['alerts'] }}</span>
                                    </div>
                                    <div class="h-2 rounded-full bg-gray-200 dark:bg-gray-800">
                                        <div class="h-2 rounded-full bg-danger-500" style="width: {{ $alertWidth }}%"></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </x-filament::section>

            <x-filament::section class="rounded-2xl shadow-sm">
                <x-slot name="heading">
                    <div class="flex items-center justify-between gap-3">
                        <div class="flex items-center gap-2">
                            <span class="flex h-9 w-9 items-center justify-center rounded-xl bg-success-100 text-success-700 dark:bg-success-900 dark:text-success-300">
                                <x-filament::icon icon="heroicon-o-sparkles" class="h-5 w-5" />
                            </span>
                            <div>
                                <p class="font-bold text-gray-950 dark:text-white">{{ __('Cleaning booking statuses') }}</p>
                                <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('Distribution by current status') }}</p>
                            </div>
                        </div>
                        <span class="rounded-full bg-success-100 px-3 py-1 text-xs font-bold text-success-700 dark:bg-success-900 dark:text-success-300">
                            {{ $cleaningStatusBreakdown['total'] }}
                        </span>
                    </div>
                </x-slot>

                <div class="space-y-3">
                    @forelse ($cleaningStatusBreakdown['items'] as $item)
                        <div class="rounded-xl border border-gray-200 p-3 dark:border-gray-800">
                            <div class="mb-2 flex items-center justify-between text-sm">
                                <span class="font-medium text-gray-800 dark:text-gray-200">{{ $item['label'] }}</span>
                                <span class="font-semibold text-gray-900 dark:text-gray-100">{{ $item['value'] }} ({{ $item['share'] }}%)</span>
                            </div>
                            <div class="h-2 rounded-full bg-gray-200 dark:bg-gray-800">
                                <div class="h-2 rounded-full {{ $item['color'] }}" style="width: {{ $item['width'] }}%"></div>
                            </div>
                        </div>
                    @empty
                        <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('No booking data available') }}</p>
                    @endforelse
                </div>
            </x-filament::section>
        </div>

        <div class="grid gap-6 xl:grid-cols-2">
            <x-filament::section class="rounded-2xl shadow-sm">
                <x-slot name="heading">
                    <div class="flex items-center justify-between gap-3">
                        <div class="flex items-center gap-2">
                            <span class="flex h-9 w-9 items-center justify-center rounded-xl bg-warning-100 text-warning-700 dark:bg-warning-900 dark:text-warning-300">
                                <x-filament::icon icon="heroicon-o-bell-alert" class="h-5 w-5" />
                            </span>
                            <div>
                                <p class="font-bold text-gray-950 dark:text-white">{{ __('Alert types (last 7 days)') }}</p>
                                <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('Most frequent operational alerts') }}</p>
                            </div>
                        </div>
                        <span class="rounded-full bg-warning-100 px-3 py-1 text-xs font-bold text-warning-700 dark:bg-warning-900 dark:text-warning-300">
                            {{ $alertTypeBreakdown['total'] }}
                        </span>
                    </div>
                </x-slot>

                <div class="space-y-3">
                    @forelse ($alertTypeBreakdown['items'] as $item)
                        <div class="rounded-xl border border-gray-200 p-3 dark:border-gray-800">
                            <div class="mb-2 flex items-center justify-between text-sm">
                                <span class="font-medium text-gray-800 dark:text-gray-200">{{ $item['label'] }}</span>
                                <span class="font-semibold text-gray-900 dark:text-gray-100">{{ $item['value'] }}</span>
                            </div>
                            <div class="h-2 rounded-full bg-gray-200 dark:bg-gray-800">
                                <div class="h-2 rounded-full {{ $item['color'] }}" style="width: {{ $item['width'] }}%"></div>
                            </div>
                        </div>
                    @empty
                        <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('No alert activity in the last 7 days') }}</p>
                    @endforelse
                </div>
            </x-filament::section>

            <x-filament::section class="rounded-2xl shadow-sm">
                <x-slot name="heading">
                    <div class="flex items-center justify-between gap-3">
                        <div class="flex items-center gap-2">
                            <span class="flex h-9 w-9 items-center justify-center rounded-xl bg-info-100 text-info-700 dark:bg-info-900 dark:text-info-300">
                                <x-filament::icon icon="heroicon-o-shield-check" class="h-5 w-5" />
                            </span>
                            <div>
                                <p class="font-bold text-gray-950 dark:text-white">{{ __('System alert lifecycle') }}</p>
                                <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('New, acknowledged, and resolved alerts') }}</p>
                            </div>
                        </div>
                        <span class="rounded-full bg-info-100 px-3 py-1 text-xs font-bold text-info-700 dark:bg-info-900 dark:text-info-300">
                            {{ $systemAlertStatusBreakdown['total'] }}
                        </span>
                    </div>
                </x-slot>

                <div class="space-y-3">
                    @forelse ($systemAlertStatusBreakdown['items'] as $item)
                        <div class="rounded-xl border border-gray-200 p-3 dark:border-gray-800">
                            <div class="mb-2 flex items-center justify-between text-sm">
                                <span class="font-medium text-gray-800 dark:text-gray-200">{{ $item['label'] }}</span>
                                <span class="font-semibold text-gray-900 dark:text-gray-100">{{ $item['value'] }}</span>
                            </div>
                            <div class="h-2 rounded-full bg-gray-200 dark:bg-gray-800">
                                <div class="h-2 rounded-full {{ $item['color'] }}" style="width: {{ $item['width'] }}%"></div>
                            </div>
                        </div>
                    @empty
                        <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('No system alert data available') }}</p>
                    @endforelse
                </div>
            </x-filament::section>
        </div>

        @if (count($sosAlerts) > 0)
            <x-filament::section class="rounded-2xl border-danger-200 bg-danger-50/60 shadow-sm dark:border-danger-800 dark:bg-danger-950/30">
                <x-slot name="heading">
                    <div class="flex items-center justify-between gap-3">
                        <div class="flex items-center gap-2">
                            <span class="flex h-9 w-9 items-center justify-center rounded-xl bg-danger-100 text-danger-700 dark:bg-danger-900 dark:text-danger-300">
                                <x-filament::icon icon="heroicon-o-exclamation-triangle" class="h-5 w-5" />
                            </span>

                            <div>
                                <p class="font-bold text-danger-800 dark:text-danger-200">
                                    {{ __('cleaning_admin.overview.alerts.sos_heading') }}
                                </p>
                                <p class="text-xs text-danger-700/80 dark:text-danger-300/80">
                                    {{ __('Needs immediate follow-up') }}
                                </p>
                            </div>
                        </div>

                        <span class="rounded-full bg-danger-600 px-3 py-1 text-xs font-bold text-white">
                            {{ count($sosAlerts) }}
                        </span>
                    </div>
                </x-slot>

                <div class="space-y-3">
                    @foreach ($sosAlerts as $alert)
                        @include('filament-hub.partials.command-alert-card', [
                            'alert' => $alert,
                            'variant' => 'danger',
                        ])
                    @endforeach
                </div>
            </x-filament::section>
        @endif

        <x-filament::section class="rounded-2xl shadow-sm">
            <x-slot name="heading">
                <div class="flex items-center justify-between gap-3">
                    <div class="flex items-center gap-2">
                        <span class="flex h-9 w-9 items-center justify-center rounded-xl bg-primary-100 text-primary-700 dark:bg-primary-900 dark:text-primary-300">
                            <x-filament::icon icon="heroicon-o-bell-alert" class="h-5 w-5" />
                        </span>

                        <div>
                            <p class="font-bold text-gray-950 dark:text-white">
                                {{ __('cleaning_admin.overview.alerts.system_heading') }}
                            </p>
                            <p class="text-xs text-gray-500 dark:text-gray-400">
                                {{ __('Latest alerts that require action') }}
                            </p>
                        </div>
                    </div>

                    <span class="rounded-full bg-primary-100 px-3 py-1 text-xs font-bold text-primary-700 dark:bg-primary-900 dark:text-primary-300">
                        {{ count($otherAlerts) }}
                    </span>
                </div>
            </x-slot>

            <div class="space-y-3">
                @forelse ($otherAlerts as $alert)
                    @include('filament-hub.partials.command-alert-card', [
                        'alert' => $alert,
                        'variant' => 'primary',
                    ])
                @empty
                    @if (count($sosAlerts) === 0)
                        <div class="rounded-2xl border border-dashed border-gray-300 p-10 text-center dark:border-gray-700">
                            <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-success-50 text-success-600 dark:bg-success-950 dark:text-success-300">
                                <x-filament::icon icon="heroicon-o-check-circle" class="h-7 w-7" />
                            </div>

                            <h3 class="mt-4 text-base font-semibold text-gray-950 dark:text-white">
                                {{ __('cleaning_admin.overview.alerts.none') }}
                            </h3>

                            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                                {{ __('Everything is operating normally.') }}
                            </p>
                        </div>
                    @endif
                @endforelse
            </div>
        </x-filament::section>
    </div>
</x-filament-hub.page-shell>
