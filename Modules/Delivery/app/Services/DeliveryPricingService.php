<?php

declare(strict_types=1);

namespace Modules\Delivery\Services;

final class DeliveryPricingService
{
    public function __construct(
        private readonly DriverLocationService $locationService,
    ) {}

    /**
     * @return array{distanceKm: float, deliveryFee: float, currency: string}
     */
    public function calculate(
        float $pickupLatitude,
        float $pickupLongitude,
        float $dropoffLatitude,
        float $dropoffLongitude,
        ?string $currency = null,
    ): array {
        $distanceKm = round($this->locationService->calculateHaversineDistance(
            $pickupLatitude,
            $pickupLongitude,
            $dropoffLatitude,
            $dropoffLongitude,
        ), 3);

        $baseFee = (float) config('delivery.pricing.base_fee', 5000);
        $perKmRate = (float) config('delivery.pricing.per_km_rate', 1000);
        $minimumFee = (float) config('delivery.pricing.minimum_fee', 5000);

        $deliveryFee = round(max($minimumFee, $baseFee + ($distanceKm * $perKmRate)), 2);

        return [
            'distanceKm' => $distanceKm,
            'deliveryFee' => $deliveryFee,
            'currency' => $currency ?? (string) config('delivery.pricing.default_currency', 'SYP'),
        ];
    }
}
