@php
    /** @var array{points: array<int, array<string, mixed>>, history: array<int, array<string, mixed>>, destination: ?array{latitude: float, longitude: float}} $state */
    $state = $getState() ?? [];
    $points = $state['points'] ?? [];
    $history = $state['history'] ?? [];
    $destination = $state['destination'] ?? null;
    $mapId = 'worker-movement-map-'.uniqid();
@endphp

<div class="space-y-4" wire:ignore>
    @if ($points === [] && $history === [])
        <div class="rounded-xl border border-dashed border-gray-300 px-4 py-6 text-center text-sm text-gray-500 dark:border-gray-700 dark:text-gray-400">
            لا توجد نقاط موقع مسجّلة لهذا الحجز بعد.
        </div>
    @else
        <link
            rel="stylesheet"
            href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"
            integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY="
            crossorigin=""
        />
        <div
            id="{{ $mapId }}"
            class="h-80 w-full overflow-hidden rounded-xl border border-gray-200 dark:border-gray-700"
        ></div>

        <div class="overflow-hidden rounded-xl border border-gray-200 dark:border-gray-700">
            <div class="border-b border-gray-200 bg-gray-50 px-4 py-2 text-sm font-semibold text-gray-950 dark:border-gray-700 dark:bg-gray-900 dark:text-white">
                آخر {{ count($history) }} نقطة موقع
            </div>
            <ul class="divide-y divide-gray-200 dark:divide-gray-700">
                @forelse ($history as $row)
                    <li class="flex flex-col gap-1 px-4 py-3 text-sm sm:flex-row sm:items-center sm:justify-between">
                        <div class="space-y-1">
                            <div class="font-medium text-gray-950 dark:text-white">
                                {{ $row['workerName'] ?? 'عامل' }}
                            </div>
                            <div class="text-gray-500 dark:text-gray-400">
                                {{ number_format((float) $row['latitude'], 6, '.', '') }},
                                {{ number_format((float) $row['longitude'], 6, '.', '') }}
                            </div>
                        </div>
                        <div class="text-gray-500 dark:text-gray-400">
                            {{ $row['recordedAt'] ?? '-' }}
                        </div>
                    </li>
                @empty
                    <li class="px-4 py-3 text-sm text-gray-500 dark:text-gray-400">
                        لا يوجد سجل حركة بعد.
                    </li>
                @endforelse
            </ul>
        </div>

        <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
        <script>
            (() => {
                const mapEl = document.getElementById(@json($mapId));
                if (!mapEl || typeof L === 'undefined') {
                    return;
                }

                const points = @json($points);
                const destination = @json($destination);
                const map = L.map(mapEl);

                L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
                    maxZoom: 19,
                    attribution: '&copy; OpenStreetMap',
                }).addTo(map);

                const bounds = [];

                points.forEach((point) => {
                    const marker = L.marker([point.latitude, point.longitude]).addTo(map);
                    marker.bindPopup(point.label || 'عامل');
                    bounds.push([point.latitude, point.longitude]);
                });

                if (destination && destination.latitude != null && destination.longitude != null) {
                    const destMarker = L.circleMarker([destination.latitude, destination.longitude], {
                        radius: 8,
                        color: '#2563eb',
                        fillColor: '#60a5fa',
                        fillOpacity: 0.9,
                    }).addTo(map);
                    destMarker.bindPopup('موقع العميل');
                    bounds.push([destination.latitude, destination.longitude]);
                }

                if (bounds.length === 1) {
                    map.setView(bounds[0], 15);
                } else if (bounds.length > 1) {
                    map.fitBounds(bounds, { padding: [24, 24] });
                } else {
                    map.setView([36.2021, 37.1343], 12);
                }
            })();
        </script>
    @endif
</div>
