<?php

declare(strict_types=1);

namespace Modules\Delivery\Services;

use Modules\Delivery\Models\DeliveryDriver;
use Modules\Delivery\Models\DeliveryDriverLocation;

final class DriverLocationService
{
    public function appendLocation(DeliveryDriver $driver, float $latitude, float $longitude, ?float $accuracy = null, ?float $speed = null, ?float $heading = null): DeliveryDriverLocation
    {
        DeliveryDriverLocation::query()->create([
            'driver_id' => $driver->id,
            'latitude' => $latitude,
            'longitude' => $longitude,
            'accuracy' => $accuracy,
            'speed' => $speed,
            'heading' => $heading,
            'recorded_at' => now(),
        ]);

        $driver->forceFill(['last_seen_at' => now()])->saveQuietly();

        return $driver->locations()->latest('recorded_at')->first();
    }

    public function calculateHaversineDistance(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $earthRadiusKm = 6371;
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) * sin($dLat / 2) + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) * sin($dLon / 2);
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadiusKm * $c;
    }
}
