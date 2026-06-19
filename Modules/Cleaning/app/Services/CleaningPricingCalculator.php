<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use App\Models\CleaningFinancialSetting;
use App\Models\Worker;
use InvalidArgumentException;

final class CleaningPricingCalculator
{
    /**
     * @return array{travelFee: float, distanceKm: ?float, adminMargin: float, totalPrice: float, isPricingFinal: bool}
     */
    public function provisional(float $basePrice, float $addonsTotal = 0.0): array
    {
        $basePrice = $this->roundMoney($basePrice);
        $addonsTotal = $this->roundMoney($addonsTotal);

        return [
            'travelFee' => 0.0,
            'distanceKm' => null,
            'adminMargin' => 0.0,
            'totalPrice' => $this->roundMoney($basePrice + $addonsTotal),
            'isPricingFinal' => false,
        ];
    }

    /**
     * @return array{travelFee: float, distanceKm: float, adminMargin: float, totalPrice: float, isPricingFinal: bool}
     */
    public function finalizedForWorker(
        float $basePrice,
        float $addonsTotal,
        ?float $customerLatitude,
        ?float $customerLongitude,
        Worker $worker,
    ): array {
        if ($worker->home_address === null || trim((string) $worker->home_address) === '') {
            throw new InvalidArgumentException('Worker home location is incomplete.');
        }

        if ($worker->home_latitude === null || $worker->home_longitude === null) {
            throw new InvalidArgumentException('Worker home location is incomplete.');
        }

        return $this->finalizedForCoordinates(
            $basePrice,
            $addonsTotal,
            $customerLatitude,
            $customerLongitude,
            (float) $worker->home_latitude,
            (float) $worker->home_longitude,
        );
    }

    /**
     * @return array{travelFee: float, distanceKm: float, adminMargin: float, totalPrice: float, isPricingFinal: bool}
     */
    public function finalizedForCoordinates(
        float $basePrice,
        float $addonsTotal,
        ?float $customerLatitude,
        ?float $customerLongitude,
        float $originLatitude,
        float $originLongitude,
    ): array {
        if ($customerLatitude === null || $customerLongitude === null) {
            throw new InvalidArgumentException('Customer location coordinates are required to finalize pricing.');
        }

        $basePrice = $this->roundMoney($basePrice);
        $addonsTotal = $this->roundMoney($addonsTotal);
        $distanceKm = round($this->haversineDistanceKm(
            (float) $customerLatitude,
            (float) $customerLongitude,
            $originLatitude,
            $originLongitude,
        ), 3);

        $financial = CleaningFinancialSetting::query()->first();

        $travelPerKm = max(0.0, (float) ($financial?->travel_per_km ?? 0.0));
        $travelFee = $this->roundMoney($distanceKm * $travelPerKm);

        $subtotal = $this->roundMoney($basePrice + $addonsTotal + $travelFee);

        $commissionType = (string) ($financial?->commission_type ?? 'percent');
        $adminMargin = $commissionType === 'fixed'
            ? $this->roundMoney(max(0.0, (float) ($financial?->commission_fixed_amount ?? 0.0)))
            : $this->roundMoney($subtotal * (max(0.0, (float) ($financial?->default_commission_rate ?? 0.0)) / 100));

        return [
            'travelFee' => $travelFee,
            'distanceKm' => $distanceKm,
            'adminMargin' => $adminMargin,
            'totalPrice' => $this->roundMoney($subtotal + $adminMargin),
            'isPricingFinal' => true,
        ];
    }

    private function roundMoney(float $amount): float
    {
        return round($amount, 2);
    }

    private function haversineDistanceKm(
        float $lat1,
        float $lng1,
        float $lat2,
        float $lng2,
    ): float {
        $earthRadiusKm = 6371.0;

        $latDelta = deg2rad($lat2 - $lat1);
        $lngDelta = deg2rad($lng2 - $lng1);

        $a = sin($latDelta / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($lngDelta / 2) ** 2;

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadiusKm * $c;
    }
}
