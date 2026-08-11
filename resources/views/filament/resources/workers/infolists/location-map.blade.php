@php
    $location = $getState() ?? [];
    $hasCoordinates = (bool) ($location['hasCoordinates'] ?? false);
    $latitude = $location['latitude'] ?? null;
    $longitude = $location['longitude'] ?? null;
    $address = $location['address'] ?? null;

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

@if ($hasCoordinates)
    <div class="space-y-3">
        <div class="overflow-hidden rounded-xl border border-gray-200 bg-gray-50 dark:border-gray-700 dark:bg-gray-900">
            <iframe
                src="{{ $embedUrl }}"
                title="موقع العامل على OpenStreetMap"
                class="block h-80 w-full"
                loading="lazy"
                referrerpolicy="no-referrer-when-downgrade"
            ></iframe>
        </div>

        <div class="flex flex-col gap-2 rounded-xl border border-gray-200 px-4 py-3 text-sm dark:border-gray-700 sm:flex-row sm:items-center sm:justify-between">
            <div class="space-y-1">
                @if (filled($address))
                    <div class="font-medium text-gray-950 dark:text-white">{{ $address }}</div>
                @endif
                <div class="text-gray-500 dark:text-gray-400">
                    خط العرض: {{ number_format($latitude, 8, '.', '') }}
                    <span class="mx-2">•</span>
                    خط الطول: {{ number_format($longitude, 8, '.', '') }}
                </div>
            </div>

            <a
                href="{{ $openMapUrl }}"
                target="_blank"
                rel="noopener noreferrer"
                class="font-semibold text-primary-600 hover:underline dark:text-primary-400"
            >
                فتح في OpenStreetMap
            </a>
        </div>
    </div>
@else
    <div class="rounded-xl border border-dashed border-gray-300 px-4 py-6 text-center text-sm text-gray-500 dark:border-gray-700 dark:text-gray-400">
        لم يتم حفظ خطوط الطول والعرض لهذا العامل بعد، لذلك لا يمكن عرض موقعه على الخريطة.
    </div>
@endif
