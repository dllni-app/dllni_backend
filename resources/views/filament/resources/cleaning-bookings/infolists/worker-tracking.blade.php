@php
    $tracking = $getState() ?? [];
    $workers = collect($tracking['workers'] ?? []);
    $requiredWorkers = (int) ($tracking['requiredWorkers'] ?? 1);
    $acceptedWorkers = (int) ($tracking['acceptedWorkers'] ?? $workers->count());
    $remainingWorkers = max(0, $requiredWorkers - $acceptedWorkers);
    $activelyTrackedWorkers = (int) ($tracking['activelyTrackedWorkers'] ?? 0);
    $bookingStatusLabel = (string) ($tracking['bookingStatusLabel'] ?? '-');
@endphp

<div wire:poll.30s="$refresh" class="space-y-5">
    <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-900">
            <div class="text-xs font-medium text-gray-500 dark:text-gray-400">حالة الطلب</div>
            <div class="mt-2 text-base font-semibold text-gray-950 dark:text-white">{{ $bookingStatusLabel }}</div>
        </div>

        <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-900">
            <div class="text-xs font-medium text-gray-500 dark:text-gray-400">العاملون المقبولون</div>
            <div class="mt-2 text-2xl font-semibold tabular-nums text-gray-950 dark:text-white">
                {{ $acceptedWorkers }} / {{ $requiredWorkers }}
            </div>
        </div>

        <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-900">
            <div class="text-xs font-medium text-gray-500 dark:text-gray-400">العاملون المتبقون</div>
            <div class="mt-2 text-2xl font-semibold tabular-nums text-gray-950 dark:text-white">{{ $remainingWorkers }}</div>
        </div>

        <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-900">
            <div class="text-xs font-medium text-gray-500 dark:text-gray-400">قيد التتبع الآن</div>
            <div class="mt-2 text-2xl font-semibold tabular-nums text-gray-950 dark:text-white">{{ $activelyTrackedWorkers }}</div>
        </div>
    </div>

    @if ($workers->isEmpty())
        <div class="rounded-2xl border border-dashed border-gray-300 bg-gray-50 px-6 py-10 text-center dark:border-gray-700 dark:bg-gray-900/50">
            <x-filament::icon icon="heroicon-o-user-group" class="mx-auto h-9 w-9 text-gray-400" />
            <div class="mt-3 font-semibold text-gray-950 dark:text-white">لا يوجد عامل مقبول لهذا الطلب بعد</div>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                ستظهر بطاقات العاملين وحالة كل عامل وموقعه هنا فور قبول الطلب.
            </p>
        </div>
    @else
        <div class="grid gap-4 2xl:grid-cols-2">
            @foreach ($workers as $worker)
                @php
                    $hasCoordinates = (bool) ($worker['hasCoordinates'] ?? false);
                    $latitude = $worker['latitude'] ?? null;
                    $longitude = $worker['longitude'] ?? null;
                    $embedUrl = null;
                    $openMapUrl = null;

                    if ($hasCoordinates) {
                        $latitude = (float) $latitude;
                        $longitude = (float) $longitude;
                        $delta = 0.01;
                        $bbox = sprintf(
                            '%.8f,%.8f,%.8f,%.8f',
                            $longitude - $delta,
                            $latitude - $delta,
                            $longitude + $delta,
                            $latitude + $delta,
                        );
                        $marker = sprintf('%.8f,%.8f', $latitude, $longitude);
                        $embedUrl = 'https://www.openstreetmap.org/export/embed.html?'.http_build_query([
                            'bbox' => $bbox,
                            'layer' => 'mapnik',
                            'marker' => $marker,
                        ], '', '&', PHP_QUERY_RFC3986);
                        $openMapUrl = sprintf(
                            'https://www.openstreetmap.org/?mlat=%.8f&mlon=%.8f#map=16/%.8f/%.8f',
                            $latitude,
                            $longitude,
                            $latitude,
                            $longitude,
                        );
                    }
                @endphp

                <article class="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-900">
                    <div class="flex flex-col gap-4 border-b border-gray-200 p-4 dark:border-gray-700 sm:flex-row sm:items-start sm:justify-between">
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <h3 class="truncate text-base font-semibold text-gray-950 dark:text-white">
                                    {{ $worker['name'] ?? 'عامل غير متاح' }}
                                </h3>
                                <x-filament::badge :color="$worker['statusColor'] ?? 'gray'">
                                    {{ $worker['statusLabel'] ?? '-' }}
                                </x-filament::badge>
                            </div>

                            <div class="mt-2 flex flex-wrap items-center gap-x-4 gap-y-1 text-sm text-gray-500 dark:text-gray-400">
                                @if (filled($worker['phone'] ?? null))
                                    <a href="tel:{{ $worker['phone'] }}" class="inline-flex items-center gap-1.5 hover:text-primary-600 dark:hover:text-primary-400">
                                        <x-filament::icon icon="heroicon-o-phone" class="h-4 w-4" />
                                        <span dir="ltr">{{ $worker['phone'] }}</span>
                                    </a>
                                @endif

                                @if (($worker['averageRating'] ?? null) !== null)
                                    <span class="inline-flex items-center gap-1.5">
                                        <x-filament::icon icon="heroicon-o-star" class="h-4 w-4" />
                                        {{ number_format((float) $worker['averageRating'], 1) }}
                                    </span>
                                @endif
                            </div>
                        </div>

                        <x-filament::badge :color="$worker['trackingColor'] ?? 'gray'" class="shrink-0">
                            {{ $worker['trackingLabel'] ?? 'غير متاح' }}
                        </x-filament::badge>
                    </div>

                    <div class="grid gap-px bg-gray-200 dark:bg-gray-700 sm:grid-cols-2 xl:grid-cols-4">
                        <div class="bg-white p-3 dark:bg-gray-900">
                            <div class="text-xs text-gray-500 dark:text-gray-400">وقت القبول</div>
                            <div class="mt-1 text-sm font-medium text-gray-950 dark:text-white">{{ $worker['acceptedAt'] ?? '-' }}</div>
                        </div>
                        <div class="bg-white p-3 dark:bg-gray-900">
                            <div class="text-xs text-gray-500 dark:text-gray-400">بدء التوجه</div>
                            <div class="mt-1 text-sm font-medium text-gray-950 dark:text-white">{{ $worker['startedTravelAt'] ?? '-' }}</div>
                        </div>
                        <div class="bg-white p-3 dark:bg-gray-900">
                            <div class="text-xs text-gray-500 dark:text-gray-400">الوصول</div>
                            <div class="mt-1 text-sm font-medium text-gray-950 dark:text-white">{{ $worker['arrivedAt'] ?? '-' }}</div>
                        </div>
                        <div class="bg-white p-3 dark:bg-gray-900">
                            <div class="text-xs text-gray-500 dark:text-gray-400">آخر تحديث موقع</div>
                            <div class="mt-1 text-sm font-medium text-gray-950 dark:text-white">{{ $worker['locationUpdatedAt'] ?? '-' }}</div>
                        </div>
                    </div>

                    @if ($hasCoordinates)
                        <div class="overflow-hidden bg-gray-50 dark:bg-gray-950">
                            <iframe
                                src="{{ $embedUrl }}"
                                title="موقع {{ $worker['name'] ?? 'العامل' }} على OpenStreetMap"
                                class="block h-72 w-full"
                                loading="lazy"
                                referrerpolicy="no-referrer-when-downgrade"
                            ></iframe>
                        </div>

                        <div class="flex flex-col gap-3 border-t border-gray-200 px-4 py-3 text-sm dark:border-gray-700 sm:flex-row sm:items-center sm:justify-between">
                            <div class="text-gray-500 dark:text-gray-400">
                                <span>خط العرض: {{ number_format($latitude, 6, '.', '') }}</span>
                                <span class="mx-2">•</span>
                                <span>خط الطول: {{ number_format($longitude, 6, '.', '') }}</span>
                            </div>

                            <a
                                href="{{ $openMapUrl }}"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="inline-flex items-center gap-1.5 font-semibold text-primary-600 hover:underline dark:text-primary-400"
                            >
                                <x-filament::icon icon="heroicon-o-map-pin" class="h-4 w-4" />
                                فتح في OpenStreetMap
                            </a>
                        </div>
                    @else
                        <div class="px-4 py-5">
                            <div class="rounded-xl border border-dashed border-gray-300 bg-gray-50 px-4 py-6 text-center dark:border-gray-700 dark:bg-gray-950">
                                <x-filament::icon icon="heroicon-o-map-pin" class="mx-auto h-7 w-7 text-gray-400" />
                                <div class="mt-2 text-sm font-medium text-gray-700 dark:text-gray-200">
                                    {{ $worker['locationEmptyLabel'] ?? 'لم يصل تحديث موقع من العامل بعد.' }}
                                </div>
                            </div>
                        </div>
                    @endif
                </article>
            @endforeach
        </div>
    @endif

    <div class="flex items-start gap-2 rounded-xl bg-gray-50 px-4 py-3 text-xs leading-5 text-gray-500 dark:bg-gray-900/60 dark:text-gray-400">
        <x-filament::icon icon="heroicon-o-information-circle" class="mt-0.5 h-4 w-4 shrink-0" />
        <p>
            يتم تحديث هذه اللوحة تلقائياً كل 30 ثانية من آخر إحداثيات محفوظة يرسلها تطبيق العامل. يتوقف استقبال تحديثات الموقع بعد تسجيل وصول العامل، وتبقى آخر نقطة محفوظة ظاهرة للإدارة.
        </p>
    </div>
</div>
