@php
    $tracking = $getState() ?? [];
    $workers = collect($tracking['workers'] ?? []);
    $requiredWorkers = (int) ($tracking['requiredWorkers'] ?? 1);
    $acceptedWorkers = (int) ($tracking['acceptedWorkers'] ?? $workers->count());
    $activelyTrackedWorkers = (int) ($tracking['activelyTrackedWorkers'] ?? 0);
    $bookingStatusLabel = (string) ($tracking['bookingStatusLabel'] ?? '-');
    $destinationLatitude = $tracking['destinationLatitude'] ?? null;
    $destinationLongitude = $tracking['destinationLongitude'] ?? null;
@endphp

@once
    <link
        rel="stylesheet"
        href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"
        crossorigin=""
    />

    <script>
        (() => {
            window.loadCleaningLeaflet = window.loadCleaningLeaflet || (() => {
                if (window.L) {
                    return Promise.resolve(window.L);
                }

                if (window.cleaningLeafletPromise) {
                    return window.cleaningLeafletPromise;
                }

                window.cleaningLeafletPromise = new Promise((resolve, reject) => {
                    const script = document.createElement('script');
                    script.src = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js';
                    script.crossOrigin = '';
                    script.onload = () => resolve(window.L);
                    script.onerror = reject;
                    document.head.appendChild(script);
                });

                return window.cleaningLeafletPromise;
            });

            window.initCleaningTrackingMaps = window.initCleaningTrackingMaps || (async (root = document) => {
                let L;

                try {
                    L = await window.loadCleaningLeaflet();
                } catch (error) {
                    return;
                }

                root.querySelectorAll?.('[data-cleaning-route-map]').forEach((element) => {
                    if (element.dataset.mapInitialized === '1') {
                        return;
                    }

                    let points = [];

                    try {
                        points = JSON.parse(atob(element.dataset.route || 'W10='));
                    } catch (error) {
                        points = [];
                    }

                    const route = points
                        .map((point) => [Number(point.latitude), Number(point.longitude)])
                        .filter(([lat, lng]) => Number.isFinite(lat) && Number.isFinite(lng));

                    const destinationLat = Number(element.dataset.destinationLatitude);
                    const destinationLng = Number(element.dataset.destinationLongitude);
                    const hasDestination = Number.isFinite(destinationLat) && Number.isFinite(destinationLng);

                    if (route.length === 0 && !hasDestination) {
                        return;
                    }

                    element.dataset.mapInitialized = '1';
                    element.innerHTML = '';

                    const map = L.map(element, {
                        scrollWheelZoom: false,
                    });

                    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                        maxZoom: 19,
                        attribution: '&copy; OpenStreetMap contributors',
                    }).addTo(map);

                    const bounds = [];

                    if (route.length > 0) {
                        route.forEach((point) => bounds.push(point));

                        if (route.length > 1) {
                            L.polyline(route, {
                                weight: 5,
                                opacity: 0.85,
                            }).addTo(map);
                        }

                        L.circleMarker(route[0], {
                            radius: 7,
                            weight: 3,
                            fillOpacity: 1,
                        }).addTo(map).bindTooltip('بداية المسار');

                        L.circleMarker(route[route.length - 1], {
                            radius: 8,
                            weight: 3,
                            fillOpacity: 1,
                        }).addTo(map).bindTooltip('آخر موقع للعامل');
                    }

                    if (hasDestination) {
                        const destination = [destinationLat, destinationLng];
                        bounds.push(destination);

                        L.marker(destination)
                            .addTo(map)
                            .bindTooltip('موقع الحجز');
                    }

                    if (bounds.length > 1) {
                        map.fitBounds(bounds, {
                            padding: [32, 32],
                            maxZoom: 16,
                        });
                    } else {
                        map.setView(bounds[0], 16);
                    }

                    element._cleaningLeafletMap = map;
                });
            });

            document.addEventListener('DOMContentLoaded', () => window.initCleaningTrackingMaps());
            document.addEventListener('livewire:navigated', () => window.initCleaningTrackingMaps());

            document.addEventListener('livewire:initialized', () => {
                Livewire.hook('morph.updated', ({ el }) => {
                    window.setTimeout(() => window.initCleaningTrackingMaps(el), 0);
                });
            });
        })();
    </script>
@endonce

<div wire:poll.30s="$refresh" class="space-y-4">
    <div class="grid gap-3 sm:grid-cols-3">
        <div class="rounded-xl border border-gray-200 bg-white p-3 dark:border-gray-700 dark:bg-gray-900">
            <div class="text-xs text-gray-500 dark:text-gray-400">حالة الطلب</div>
            <div class="mt-1 font-semibold text-gray-950 dark:text-white">{{ $bookingStatusLabel }}</div>
        </div>

        <div class="rounded-xl border border-gray-200 bg-white p-3 dark:border-gray-700 dark:bg-gray-900">
            <div class="text-xs text-gray-500 dark:text-gray-400">الفريق المؤكد</div>
            <div class="mt-1 font-semibold text-gray-950 dark:text-white">{{ $acceptedWorkers }} من {{ $requiredWorkers }}</div>
        </div>

        <div class="rounded-xl border border-gray-200 bg-white p-3 dark:border-gray-700 dark:bg-gray-900">
            <div class="text-xs text-gray-500 dark:text-gray-400">قيد التتبع الآن</div>
            <div class="mt-1 font-semibold text-gray-950 dark:text-white">{{ $activelyTrackedWorkers }}</div>
        </div>
    </div>

    @if ($workers->isEmpty())
        <div class="rounded-xl border border-dashed border-gray-300 bg-gray-50 px-5 py-7 text-center dark:border-gray-700 dark:bg-gray-900/50">
            <x-filament::icon icon="heroicon-o-map-pin" class="mx-auto h-8 w-8 text-gray-400" />
            <div class="mt-2 font-semibold text-gray-950 dark:text-white">لا يوجد عامل مؤكد لهذا الحجز بعد</div>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                ستظهر الخريطة ومسار كل عامل بعد بدء التوجه وإرسال تحديثات الموقع من التطبيق.
            </p>
        </div>
    @else
        <div class="space-y-4">
            @foreach ($workers as $worker)
                @php
                    $routePoints = array_values($worker['routePoints'] ?? []);
                    $routePayload = base64_encode(json_encode($routePoints, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]');
                    $hasCoordinates = (bool) ($worker['hasCoordinates'] ?? false);
                    $routePointsCount = (int) ($worker['routePointsCount'] ?? count($routePoints));
                    $latitude = $worker['latitude'] ?? null;
                    $longitude = $worker['longitude'] ?? null;
                    $mapKey = 'worker-route-map-'.($worker['assignmentId'] ?? 'legacy-'.$worker['workerId']).'-'.$routePointsCount;
                    $openMapUrl = null;

                    if ($hasCoordinates) {
                        $openMapUrl = sprintf(
                            'https://www.openstreetmap.org/?mlat=%.8f&mlon=%.8f#map=16/%.8f/%.8f',
                            (float) $latitude,
                            (float) $longitude,
                            (float) $latitude,
                            (float) $longitude,
                        );
                    }
                @endphp

                <article class="overflow-hidden rounded-xl border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-900">
                    <div class="flex flex-col gap-3 border-b border-gray-200 px-4 py-3 dark:border-gray-700 sm:flex-row sm:items-center sm:justify-between">
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <h3 class="font-semibold text-gray-950 dark:text-white">
                                    {{ $worker['name'] ?? 'عامل غير متاح' }}
                                </h3>

                                <x-filament::badge :color="$worker['statusColor'] ?? 'gray'">
                                    {{ $worker['statusLabel'] ?? '-' }}
                                </x-filament::badge>
                            </div>

                            <div class="mt-1 flex flex-wrap gap-x-4 gap-y-1 text-xs text-gray-500 dark:text-gray-400">
                                <span>بدء التوجه: {{ $worker['startedTravelAt'] ?? '-' }}</span>
                                <span>الوصول: {{ $worker['arrivedAt'] ?? '-' }}</span>
                                <span>آخر تحديث: {{ $worker['locationUpdatedAt'] ?? '-' }}</span>
                            </div>
                        </div>

                        <div class="flex flex-wrap items-center gap-2">
                            <x-filament::badge :color="$worker['trackingColor'] ?? 'gray'">
                                {{ $worker['trackingLabel'] ?? 'غير متاح' }}
                            </x-filament::badge>

                            <x-filament::badge color="info">
                                {{ $routePointsCount }} نقطة مسجلة
                            </x-filament::badge>
                        </div>
                    </div>

                    @if ($routePointsCount > 0 || ($destinationLatitude !== null && $destinationLongitude !== null))
                        <div
                            wire:key="{{ $mapKey }}"
                            wire:ignore
                            data-cleaning-route-map
                            data-route="{{ $routePayload }}"
                            data-destination-latitude="{{ $destinationLatitude }}"
                            data-destination-longitude="{{ $destinationLongitude }}"
                            class="h-80 w-full bg-gray-100 dark:bg-gray-950"
                        >
                            <div class="flex h-full items-center justify-center text-sm text-gray-500">
                                جارٍ تحميل الخريطة...
                            </div>
                        </div>

                        <div class="flex flex-col gap-2 border-t border-gray-200 px-4 py-3 text-xs text-gray-500 dark:border-gray-700 dark:text-gray-400 sm:flex-row sm:items-center sm:justify-between">
                            <div>
                                @if ($routePointsCount > 1)
                                    المسار الظاهر هو الطريق الفعلي المسجل من تحديثات موقع العامل أثناء التوجه.
                                @elseif ($routePointsCount === 1)
                                    توجد نقطة واحدة محفوظة حالياً؛ سيظهر المسار عند وصول تحديثات إضافية.
                                @else
                                    لم تصل إحداثيات للعامل بعد؛ تظهر نقطة موقع الحجز فقط.
                                @endif
                            </div>

                            @if ($openMapUrl)
                                <a
                                    href="{{ $openMapUrl }}"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    class="font-semibold text-primary-600 hover:underline dark:text-primary-400"
                                >
                                    فتح آخر موقع في OpenStreetMap
                                </a>
                            @endif
                        </div>
                    @else
                        <div class="px-4 py-5">
                            <div class="rounded-lg border border-dashed border-gray-300 bg-gray-50 px-4 py-5 text-center text-sm text-gray-600 dark:border-gray-700 dark:bg-gray-950 dark:text-gray-300">
                                {{ $worker['locationEmptyLabel'] ?? 'لم يصل تحديث موقع من العامل بعد.' }}
                            </div>
                        </div>
                    @endif
                </article>
            @endforeach
        </div>
    @endif

    <div class="rounded-lg bg-gray-50 px-4 py-3 text-xs leading-5 text-gray-500 dark:bg-gray-900/60 dark:text-gray-400">
        يتم حفظ نقاط المسار أثناء توجه العامل إلى موقع الحجز. بعد تسجيل الوصول يتوقف استقبال التحديثات وتبقى الخريطة والمسار المحفوظان متاحين للإدارة.
    </div>
</div>
