<?php

declare(strict_types=1);

namespace Modules\User\Services;

use Illuminate\Support\Arr;

final class UserCleaningOrderEstimationService
{
    /**
     * @param  array<string, mixed>  $propertyDetails
     * @return array{estimatedSqm: float, estimatedHours: float, sizeTier: string}
     */
    public function estimate(string $propertyType, array $propertyDetails): array
    {
        $bedrooms = max(0, (int) Arr::get($propertyDetails, 'bedrooms', 0));
        $rooms = max(0, (int) Arr::get($propertyDetails, 'rooms', 0));
        $bathrooms = max(0, (int) Arr::get($propertyDetails, 'bathrooms', 0));
        $livingRoomSize = (string) Arr::get($propertyDetails, 'living_room_size', 'medium');

        $baseSqm = $this->baseSqmByPropertyType($propertyType);
        $livingRoomSqm = $this->livingRoomSqmAdjustment($livingRoomSize);

        $estimatedSqm = max(25.0, $baseSqm + ($bedrooms * 18.0) + ($rooms * 8.0) + ($bathrooms * 6.0) + $livingRoomSqm);

        $rawHours = ($estimatedSqm / 35.0)
            + ($bathrooms * 0.25)
            + ($livingRoomSize === 'large' ? 0.25 : 0.0)
            + ($livingRoomSize === 'very_large' ? 0.50 : 0.0);

        $estimatedHours = max(1.0, $this->roundToHalfHour($rawHours));

        return [
            'estimatedSqm' => $estimatedSqm,
            'estimatedHours' => $estimatedHours,
            'sizeTier' => $this->sizeTier($estimatedSqm),
        ];
    }

    /**
     * @param  array<string, mixed>  $propertyDetails
     * @return array{basePrice: float, travelFee: float, addonsTotal: float, totalPrice: float, currency: string}
     */
    public function price(
        string $propertyType,
        array $propertyDetails,
        mixed $addressLatitude,
        mixed $addressLongitude,
        mixed $preferredWorkerId = null,
    ): array {
        $estimation = $this->estimate($propertyType, $propertyDetails);

        $pricePerSqm = $this->pricePerSqmByPropertyType($propertyType);
        $basePrice = round(max(250.0, $estimation['estimatedSqm'] * $pricePerSqm), 2);

        $hasCoordinates = is_numeric($addressLatitude) && is_numeric($addressLongitude);
        $travelFee = $hasCoordinates ? 150.0 : 200.0;

        $addonsTotal = $preferredWorkerId !== null ? 100.0 : 0.0;

        return [
            'basePrice' => $basePrice,
            'travelFee' => $travelFee,
            'addonsTotal' => $addonsTotal,
            'totalPrice' => round($basePrice + $travelFee + $addonsTotal, 2),
            'currency' => (string) config('app.currency', 'SYP'),
        ];
    }

    private function baseSqmByPropertyType(string $propertyType): float
    {
        return match ($propertyType) {
            'villa' => 120.0,
            'house' => 90.0,
            'office' => 75.0,
            default => 65.0,
        };
    }

    private function livingRoomSqmAdjustment(string $livingRoomSize): float
    {
        return match ($livingRoomSize) {
            'small' => 10.0,
            'large' => 25.0,
            'very_large' => 40.0,
            default => 15.0,
        };
    }

    private function pricePerSqmByPropertyType(string $propertyType): float
    {
        return match ($propertyType) {
            'villa' => 9.0,
            'house' => 8.0,
            'office' => 8.5,
            default => 8.0,
        };
    }

    private function roundToHalfHour(float $hours): float
    {
        return ceil($hours * 2.0) / 2.0;
    }

    private function sizeTier(float $estimatedSqm): string
    {
        if ($estimatedSqm < 80.0) {
            return 'small';
        }

        if ($estimatedSqm < 140.0) {
            return 'medium';
        }

        if ($estimatedSqm < 220.0) {
            return 'large';
        }

        return 'very_large';
    }
}
