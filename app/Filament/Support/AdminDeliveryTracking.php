<?php

declare(strict_types=1);

namespace App\Filament\Support;

use Modules\Delivery\Models\DeliveryOrder;
use Modules\Delivery\Support\DeliveryPresentation;

final class AdminDeliveryTracking
{
    /** @return array<string,mixed> */
    public static function payload(?DeliveryOrder $deliveryOrder): array
    {
        if (! $deliveryOrder instanceof DeliveryOrder) {
            return [];
        }

        $deliveryOrder->loadMissing([
            'company',
            'driver.user',
            'driver.latestLocation',
            'events',
        ]);

        return DeliveryPresentation::orderTracking($deliveryOrder);
    }

    public static function etaText(?DeliveryOrder $deliveryOrder): string
    {
        return (string) data_get(self::payload($deliveryOrder), 'eta.text', '—');
    }

    public static function etaMinutes(?DeliveryOrder $deliveryOrder): string
    {
        $minutes = data_get(self::payload($deliveryOrder), 'eta.minutes');

        return is_numeric($minutes) ? ((int) $minutes).' دقيقة' : '—';
    }

    public static function routeDistance(?DeliveryOrder $deliveryOrder): string
    {
        $distance = data_get(self::payload($deliveryOrder), 'map.routeDistanceKm');

        return is_numeric($distance) ? number_format((float) $distance, 2).' كم' : '—';
    }

    public static function pickup(?DeliveryOrder $deliveryOrder): string
    {
        return self::pointLabel(data_get(self::payload($deliveryOrder), 'pickup'));
    }

    public static function dropoff(?DeliveryOrder $deliveryOrder): string
    {
        return self::pointLabel(data_get(self::payload($deliveryOrder), 'dropoff'));
    }

    public static function driverMarker(?DeliveryOrder $deliveryOrder): string
    {
        $marker = collect((array) data_get(self::payload($deliveryOrder), 'map.markers', []))
            ->firstWhere('kind', 'driver');

        if (! is_array($marker)) {
            return '—';
        }

        $lat = $marker['latitude'] ?? null;
        $lng = $marker['longitude'] ?? null;

        return is_numeric($lat) && is_numeric($lng)
            ? number_format((float) $lat, 6).', '.number_format((float) $lng, 6)
            : '—';
    }

    public static function timelineSummary(?DeliveryOrder $deliveryOrder): string
    {
        $stages = (array) data_get(self::payload($deliveryOrder), 'timeline', []);

        $visible = collect($stages)
            ->filter(fn (mixed $stage): bool => is_array($stage) && (($stage['completed'] ?? false) || ($stage['active'] ?? false)))
            ->map(function (array $stage): string {
                $label = self::stageLabel((string) ($stage['key'] ?? ''));
                $suffix = ($stage['active'] ?? false) ? ' (الحالي)' : '';

                return $label.$suffix;
            })
            ->values()
            ->all();

        return $visible === [] ? '—' : implode(' ← ', $visible);
    }

    private static function pointLabel(mixed $point): string
    {
        if (! is_array($point)) {
            return '—';
        }

        $address = mb_trim((string) ($point['address'] ?? ''));
        $lat = $point['latitude'] ?? null;
        $lng = $point['longitude'] ?? null;
        $coords = is_numeric($lat) && is_numeric($lng)
            ? number_format((float) $lat, 6).', '.number_format((float) $lng, 6)
            : null;

        return implode(' — ', array_filter([$address !== '' ? $address : null, $coords])) ?: '—';
    }

    private static function stageLabel(string $key): string
    {
        return match ($key) {
            'created' => 'تم إنشاء طلب التوصيل',
            'searching_driver' => 'البحث عن مندوب',
            'driver_en_route' => 'المندوب متجه للاستلام',
            'arrived_pickup' => 'وصل إلى نقطة الاستلام',
            'handover_complete' => 'تم استلام الطلب',
            'delivered' => 'تم التسليم',
            'completed' => 'مكتمل',
            'stopped' => 'متوقف',
            'cancelled' => 'ملغي',
            default => $key !== '' ? str_replace('_', ' ', $key) : '—',
        };
    }
}
