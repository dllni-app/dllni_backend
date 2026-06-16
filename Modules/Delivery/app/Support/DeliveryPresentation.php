<?php

declare(strict_types=1);

namespace Modules\Delivery\Support;

use Illuminate\Support\Carbon;
use Modules\Delivery\Enums\DeliveryOrderStatus;
use Modules\Delivery\Models\DeliveryDriver;
use Modules\Delivery\Models\DeliveryDriverLocation;
use Modules\Delivery\Models\DeliveryOrder;
use Modules\Delivery\Services\DriverLocationService;

final class DeliveryPresentation
{
    private const STATUS_LABELS_AR = [
        DeliveryOrderStatus::New->value => 'تم إنشاء الطلب',
        DeliveryOrderStatus::Dispatching->value => 'جاري البحث عن سائق',
        DeliveryOrderStatus::Offered->value => 'تم إرسال العرض للسائق',
        DeliveryOrderStatus::Accepted->value => 'تم قبول الطلب',
        DeliveryOrderStatus::InProgress->value => 'السائق في الطريق إلى نقطة الاستلام',
        DeliveryOrderStatus::PickedUp->value => 'تم استلام الطلب',
        DeliveryOrderStatus::Delivered->value => 'تم التسليم',
        DeliveryOrderStatus::Completed->value => 'مكتمل',
        DeliveryOrderStatus::Rejected->value => 'مرفوض',
        DeliveryOrderStatus::Stopped->value => 'متوقف',
        DeliveryOrderStatus::Cancelled->value => 'ملغي',
    ];

    public static function statusLabelAr(?string $status): ?string
    {
        if ($status === null || $status === '') {
            return null;
        }

        $status = self::normalizeStatus($status);

        return self::STATUS_LABELS_AR[$status] ?? $status;
    }

    public static function orderTracking(DeliveryOrder $order): array
    {
        $status = self::normalizeStatus($order->status);
        $driver = $order->relationLoaded('driver') ? $order->driver : null;
        $latestLocation = self::latestDriverLocation($driver);
        $pickup = self::pointPayload(
            kind: 'pickup',
            latitude: $order->pickup_latitude,
            longitude: $order->pickup_longitude,
            address: $order->pickup_address,
        );
        $dropoff = self::pointPayload(
            kind: 'dropoff',
            latitude: $order->dropoff_latitude,
            longitude: $order->dropoff_longitude,
            address: $order->dropoff_address,
        );
        $route = array_values(array_filter([$pickup, $dropoff]));

        return [
            'currentStatus' => $status,
            'currentStatusLabelAr' => self::statusLabelAr($status),
            'eta' => self::etaPayload($order, $latestLocation, $status),
            'map' => self::mapPayload($pickup, $dropoff, $latestLocation),
            'timeline' => self::trackingTimeline($order, $status),
            'stages' => self::trackingTimeline($order, $status),
            'driver' => $driver ? self::driverPayload($driver, $latestLocation) : null,
            'pickup' => $pickup,
            'dropoff' => $dropoff,
            'route' => $route,
        ];
    }

    public static function merchantSummary(object $order): ?array
    {
        $orderType = self::normalizeStatus(data_get($order, 'order_type'));
        if ($orderType !== null && $orderType !== 'delivery') {
            return null;
        }

        $status = self::normalizeStatus(data_get($order, 'status'));
        $readyAt = self::formatDate(data_get($order, 'ready_for_pickup_at'));
        $pickedUpAt = self::formatDate(data_get($order, 'picked_up_at'));
        $completedAt = self::formatDate(data_get($order, 'completed_at'));
        $cancelledAt = self::formatDate(data_get($order, 'cancelled_at'));
        $cancelledReason = self::stringValue(data_get($order, 'cancellation_reason'))
            ?? self::stringValue(data_get($order, 'cancel_reason'))
            ?? self::stringValue(data_get($order, 'stop_reason'));

        $currentStage = self::merchantCurrentStage($status, $readyAt, $pickedUpAt, $completedAt, $cancelledAt, $cancelledReason);

        return [
            'enabled' => true,
            'status' => $status,
            'statusLabelAr' => self::statusLabelAr($status),
            'currentStage' => $currentStage,
            'isTerminal' => in_array($currentStage, ['handover_complete', 'completed', 'not_received', 'cancelled'], true),
            'pickupMode' => self::normalizeStatus(data_get($order, 'pickup_mode')),
            'readyForPickupAt' => $readyAt,
            'pickedUpAt' => $pickedUpAt,
            'completedAt' => $completedAt,
            'cancelledAt' => $cancelledAt,
            'cancellationReason' => $cancelledReason,
            'timeline' => self::merchantTimeline($status, $readyAt, $pickedUpAt, $completedAt, $cancelledAt, $cancelledReason),
        ];
    }

    /**
     * @return array{kind:string,latitude:float,longitude:float,address:?string}|null
     */
    private static function pointPayload(string $kind, mixed $latitude, mixed $longitude, mixed $address): ?array
    {
        $lat = self::floatValue($latitude);
        $lng = self::floatValue($longitude);
        if ($lat === null || $lng === null) {
            return null;
        }

        return [
            'kind' => $kind,
            'latitude' => $lat,
            'longitude' => $lng,
            'address' => self::stringValue($address),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function driverPayload(DeliveryDriver $driver, ?DeliveryDriverLocation $latestLocation): array
    {
        return [
            'id' => $driver->id,
            'userId' => $driver->user_id,
            'companyId' => $driver->company_id,
            'firstName' => $driver->first_name,
            'displayName' => self::driverDisplayName($driver),
            'phone' => $driver->phone,
            'vehicleType' => $driver->vehicle_type,
            'plateNumber' => $driver->plate_number,
            'availabilityStatus' => $driver->availability_status,
            'isActive' => (bool) $driver->is_active,
            'isSuspended' => (bool) $driver->is_suspended,
            'trustScore' => $driver->trust_score,
            'openDisputesCount' => $driver->open_disputes_count,
            'lastSeenAt' => $driver->last_seen_at?->toIso8601String(),
            'latestLocation' => $latestLocation ? [
                'id' => $latestLocation->id,
                'driverId' => $latestLocation->driver_id,
                'latitude' => (float) $latestLocation->latitude,
                'longitude' => (float) $latestLocation->longitude,
                'accuracy' => $latestLocation->accuracy ? (float) $latestLocation->accuracy : null,
                'speed' => $latestLocation->speed ? (float) $latestLocation->speed : null,
                'heading' => $latestLocation->heading ? (float) $latestLocation->heading : null,
                'recordedAt' => $latestLocation->recorded_at?->toIso8601String(),
            ] : null,
        ];
    }

    private static function etaPayload(DeliveryOrder $order, ?DeliveryDriverLocation $latestLocation, string $status): array
    {
        $minutes = null;
        $text = 'جاري التحديث';
        $referenceDistanceKm = null;

        if (in_array($status, [DeliveryOrderStatus::New->value, DeliveryOrderStatus::Dispatching->value, DeliveryOrderStatus::Offered->value], true)) {
            $text = 'جاري البحث عن سائق';
        } elseif (in_array($status, [DeliveryOrderStatus::Accepted->value, DeliveryOrderStatus::InProgress->value], true)) {
            if ($latestLocation !== null) {
                $referenceDistanceKm = self::distanceKm(
                    (float) $latestLocation->latitude,
                    (float) $latestLocation->longitude,
                    (float) $order->pickup_latitude,
                    (float) $order->pickup_longitude,
                );
                $minutes = max(3, (int) ceil(($referenceDistanceKm / 25) * 60));
                $text = $status === DeliveryOrderStatus::Accepted->value
                    ? 'السائق في الطريق إلى نقطة الاستلام'
                    : 'السائق يقترب من نقطة الاستلام';
            } else {
                $text = $status === DeliveryOrderStatus::Accepted->value
                    ? 'السائق في الطريق إلى نقطة الاستلام'
                    : 'السائق يقترب من نقطة الاستلام';
            }
        } elseif ($status === DeliveryOrderStatus::PickedUp->value) {
            $referenceDistanceKm = (float) $order->distance_km;
            $minutes = max(5, (int) ceil(($referenceDistanceKm / 28) * 60));
            $text = 'في الطريق إلى الوجهة';
        } elseif (in_array($status, [DeliveryOrderStatus::Delivered->value, DeliveryOrderStatus::Completed->value], true)) {
            $text = 'تم التسليم';
        } elseif (in_array($status, [DeliveryOrderStatus::Rejected->value, DeliveryOrderStatus::Stopped->value, DeliveryOrderStatus::Cancelled->value], true)) {
            $text = 'الطلب غير متاح حالياً';
        }

        return [
            'minutes' => $minutes,
            'text' => $text,
            'referenceDistanceKm' => $referenceDistanceKm,
            'updatedAt' => now()->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function mapPayload(?array $pickup, ?array $dropoff, ?DeliveryDriverLocation $latestLocation): array
    {
        $markers = array_values(array_filter([
            $pickup ? ['kind' => 'pickup', ...$pickup] : null,
            $dropoff ? ['kind' => 'dropoff', ...$dropoff] : null,
            $latestLocation ? [
                'kind' => 'driver',
                'latitude' => (float) $latestLocation->latitude,
                'longitude' => (float) $latestLocation->longitude,
                'accuracy' => $latestLocation->accuracy ? (float) $latestLocation->accuracy : null,
                'speed' => $latestLocation->speed ? (float) $latestLocation->speed : null,
                'heading' => $latestLocation->heading ? (float) $latestLocation->heading : null,
                'recordedAt' => $latestLocation->recorded_at?->toIso8601String(),
            ] : null,
        ]));

        $route = array_values(array_filter([
            $pickup ? ['latitude' => $pickup['latitude'], 'longitude' => $pickup['longitude']] : null,
            $dropoff ? ['latitude' => $dropoff['latitude'], 'longitude' => $dropoff['longitude']] : null,
        ]));

        $center = self::centerPoint($markers);

        return [
            'enabled' => ! empty($markers),
            'centerLatitude' => $center['latitude'],
            'centerLongitude' => $center['longitude'],
            'zoom' => 13.5,
            'markers' => $markers,
            'route' => $route,
            'routeDistanceKm' => ($pickup && $dropoff)
                ? self::distanceKm((float) $pickup['latitude'], (float) $pickup['longitude'], (float) $dropoff['latitude'], (float) $dropoff['longitude'])
                : null,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function trackingTimeline(DeliveryOrder $order, string $status): array
    {
        $createdAt = self::formatDate($order->created_at);
        $acceptedAt = self::formatDate($order->accepted_at);
        $startedAt = self::formatDate($order->started_at);
        $pickedUpAt = self::formatDate($order->picked_up_at);
        $deliveredAt = self::formatDate($order->delivered_at);
        $completedAt = self::formatDate($order->completed_at);
        $stoppedAt = self::formatDate($order->stopped_at);
        $cancelledAt = self::formatDate($order->cancelled_at);

        return [
            self::stage('created', $createdAt, true, true),
            self::stage('searching_driver', $createdAt, in_array($status, [
                DeliveryOrderStatus::Dispatching->value,
                DeliveryOrderStatus::Offered->value,
                DeliveryOrderStatus::Accepted->value,
                DeliveryOrderStatus::InProgress->value,
                DeliveryOrderStatus::PickedUp->value,
                DeliveryOrderStatus::Delivered->value,
                DeliveryOrderStatus::Completed->value,
            ], true), $status === DeliveryOrderStatus::Dispatching->value || $status === DeliveryOrderStatus::Offered->value),
            self::stage('driver_en_route', $acceptedAt, in_array($status, [
                DeliveryOrderStatus::Accepted->value,
                DeliveryOrderStatus::InProgress->value,
                DeliveryOrderStatus::PickedUp->value,
                DeliveryOrderStatus::Delivered->value,
                DeliveryOrderStatus::Completed->value,
            ], true), $status === DeliveryOrderStatus::Accepted->value),
            self::stage('arrived_pickup', $startedAt, in_array($status, [
                DeliveryOrderStatus::InProgress->value,
                DeliveryOrderStatus::PickedUp->value,
                DeliveryOrderStatus::Delivered->value,
                DeliveryOrderStatus::Completed->value,
            ], true), $status === DeliveryOrderStatus::InProgress->value),
            self::stage('handover_complete', $pickedUpAt, in_array($status, [
                DeliveryOrderStatus::PickedUp->value,
                DeliveryOrderStatus::Delivered->value,
                DeliveryOrderStatus::Completed->value,
            ], true), $status === DeliveryOrderStatus::PickedUp->value),
            self::stage('delivered', $deliveredAt, in_array($status, [
                DeliveryOrderStatus::Delivered->value,
                DeliveryOrderStatus::Completed->value,
            ], true), $status === DeliveryOrderStatus::Delivered->value),
            self::stage('completed', $completedAt, $status === DeliveryOrderStatus::Completed->value, $status === DeliveryOrderStatus::Completed->value),
            self::stage('stopped', $stoppedAt, $status === DeliveryOrderStatus::Stopped->value, $status === DeliveryOrderStatus::Stopped->value),
            self::stage('cancelled', $cancelledAt, in_array($status, [
                DeliveryOrderStatus::Rejected->value,
                DeliveryOrderStatus::Cancelled->value,
            ], true), in_array($status, [
                DeliveryOrderStatus::Rejected->value,
                DeliveryOrderStatus::Cancelled->value,
            ], true)),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function merchantTimeline(
        string $status,
        ?string $readyAt,
        ?string $pickedUpAt,
        ?string $completedAt,
        ?string $cancelledAt,
        ?string $cancelledReason,
    ): array {
        $notReceived = $cancelledAt !== null && $pickedUpAt === null && $readyAt !== null;
        $driverEnRouteCompleted = $readyAt !== null || $pickedUpAt !== null || $completedAt !== null || $cancelledAt !== null || in_array($status, ['accepted', 'preparing', 'ready_for_pickup', 'picked_up', 'completed', 'cancelled', 'stopped', 'rejected'], true);
        $driverEnRouteActive = in_array($status, ['pending', 'accepted', 'preparing'], true) && $readyAt === null && $pickedUpAt === null && $cancelledAt === null;
        $arrivedCompleted = $readyAt !== null || $pickedUpAt !== null || $completedAt !== null || $cancelledAt !== null;
        $arrivedActive = $readyAt !== null && $pickedUpAt === null && $cancelledAt === null;
        $handoverCompleted = $pickedUpAt !== null || $completedAt !== null;
        $handoverActive = $pickedUpAt !== null && $completedAt === null && $cancelledAt === null;
        $completedCompleted = $completedAt !== null;
        $completedActive = $completedAt !== null;
        $cancelledCompleted = $cancelledAt !== null && ! $notReceived;
        $cancelledActive = $cancelledAt !== null && ! $notReceived;

        return [
            self::stage('driver_en_route', $readyAt ?? $pickedUpAt ?? $completedAt ?? $cancelledAt, $driverEnRouteCompleted, $driverEnRouteActive),
            self::stage('arrived', $readyAt, $arrivedCompleted, $arrivedActive),
            self::stage('handover_complete', $pickedUpAt, $handoverCompleted, $handoverActive),
            self::stage('completed', $completedAt, $completedCompleted, $completedActive),
            self::stage('not_received', $cancelledAt, $notReceived, $notReceived),
            self::stage('cancelled', $cancelledAt, $cancelledCompleted, $cancelledActive),
        ];
    }

    private static function merchantCurrentStage(
        string $status,
        ?string $readyAt,
        ?string $pickedUpAt,
        ?string $completedAt,
        ?string $cancelledAt,
        ?string $cancelledReason,
    ): string {
        if ($completedAt !== null || $status === 'completed') {
            return 'completed';
        }

        if ($cancelledAt !== null) {
            if ($pickedUpAt === null && $readyAt !== null) {
                return 'not_received';
            }

            return 'cancelled';
        }

        if ($pickedUpAt !== null || $status === 'picked_up') {
            return 'handover_complete';
        }

        if ($readyAt !== null || in_array($status, ['ready_for_pickup'], true)) {
            return 'arrived';
        }

        if (in_array($status, ['accepted', 'preparing', 'pending'], true)) {
            return 'driver_en_route';
        }

        return 'driver_en_route';
    }

    /**
     * @return array<string, mixed>
     */
    private static function stage(string $key, ?string $timestamp, bool $completed, bool $active): array
    {
        return [
            'key' => $key,
            'timestamp' => $timestamp,
            'completed' => $completed,
            'active' => $active,
        ];
    }

    private static function latestDriverLocation(?DeliveryDriver $driver): ?DeliveryDriverLocation
    {
        if ($driver === null) {
            return null;
        }

        if ($driver->relationLoaded('latestLocation')) {
            $location = $driver->getRelation('latestLocation');
            return $location instanceof DeliveryDriverLocation ? $location : null;
        }

        return null;
    }

    private static function driverDisplayName(DeliveryDriver $driver): ?string
    {
        $displayName = self::stringValue(data_get($driver, 'user.name'));
        if ($displayName !== null && $displayName !== '') {
            return $displayName;
        }

        $displayName = self::stringValue($driver->first_name);
        return $displayName !== '' ? $displayName : null;
    }

    private static function centerPoint(array $markers): array
    {
        $points = array_values(array_filter($markers, static function (array $marker): bool {
            return isset($marker['latitude'], $marker['longitude']) && $marker['latitude'] !== null && $marker['longitude'] !== null;
        }));

        if ($points === []) {
            return ['latitude' => null, 'longitude' => null];
        }

        $latitude = array_sum(array_map(static fn (array $marker): float => (float) $marker['latitude'], $points)) / count($points);
        $longitude = array_sum(array_map(static fn (array $marker): float => (float) $marker['longitude'], $points)) / count($points);

        return [
            'latitude' => $latitude,
            'longitude' => $longitude,
        ];
    }

    private static function distanceKm(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        return app(DriverLocationService::class)->calculateHaversineDistance($lat1, $lon1, $lat2, $lon2);
    }

    private static function formatDate(mixed $value): ?string
    {
        if ($value instanceof Carbon) {
            return $value->toIso8601String();
        }

        if (is_string($value) && trim($value) !== '') {
            return Carbon::parse($value)->toIso8601String();
        }

        return null;
    }

    private static function normalizeStatus(mixed $value): ?string
    {
        if ($value instanceof \UnitEnum) {
            return (string) $value->value;
        }

        if (is_object($value) && property_exists($value, 'value')) {
            return self::normalizeStatus($value->value);
        }

        if (is_string($value) || is_int($value) || is_float($value)) {
            $value = trim((string) $value);

            return $value === '' ? null : mb_strtolower($value);
        }

        return null;
    }

    private static function floatValue(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value)) {
            return (float) $value;
        }

        return null;
    }

    private static function stringValue(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value)) {
            return trim($value);
        }

        if (is_scalar($value)) {
            return trim((string) $value);
        }

        return null;
    }
}
