<?php

declare(strict_types=1);

namespace Modules\User\Services;

use Illuminate\Support\Arr;

final class UserCleaningOrderEstimationService
{
    public const ALGORITHM_VERSION = '2026-04-08-v1';

    /**
     * @var array<int, string>
     */
    public const PROPERTY_TYPES = [
        'apartment',
        'villa',
        'house',
        'office',
    ];

    /**
     * @var array<int, string>
     */
    public const LIVING_ROOM_SIZES = [
        'small',
        'medium',
        'large',
        'very_large',
    ];

    public function algorithmVersion(): string
    {
        return self::ALGORITHM_VERSION;
    }

    public function normalizePropertyType(string $propertyType): string
    {
        $normalized = mb_strtolower(mb_trim($propertyType));

        if (! in_array($normalized, self::PROPERTY_TYPES, true)) {
            return 'apartment';
        }

        return $normalized;
    }

    /**
     * @param  array<string, mixed>  $propertyDetails
     * @return array{address: ?string, location_name: ?string, bedrooms: int, rooms: int, bathrooms: int, living_room_size: string}
     */
    public function normalizePropertyDetails(array $propertyDetails): array
    {
        $livingRoomSize = mb_strtolower((string) Arr::get($propertyDetails, 'living_room_size', 'medium'));
        if (! in_array($livingRoomSize, self::LIVING_ROOM_SIZES, true)) {
            $livingRoomSize = 'medium';
        }

        $address = Arr::has($propertyDetails, 'address')
            ? mb_trim((string) Arr::get($propertyDetails, 'address'))
            : null;

        $locationName = Arr::has($propertyDetails, 'location_name')
            ? mb_trim((string) Arr::get($propertyDetails, 'location_name'))
            : null;

        return [
            'address' => $address !== '' ? $address : null,
            'location_name' => $locationName !== '' ? $locationName : null,
            'bedrooms' => max(0, (int) Arr::get($propertyDetails, 'bedrooms', 0)),
            'rooms' => max(0, (int) Arr::get($propertyDetails, 'rooms', 0)),
            'bathrooms' => max(0, (int) Arr::get($propertyDetails, 'bathrooms', 0)),
            'living_room_size' => $livingRoomSize,
        ];
    }

    /**
     * @param  array<string, mixed>  $propertyDetails
     * @return array{propertyType: string, propertyDetails: array{bedrooms: int, rooms: int, bathrooms: int, living_room_size: string}, addressLatitude: ?float, addressLongitude: ?float, preferredWorkerId: ?int}
     */
    public function pricingSnapshotInput(
        string $propertyType,
        array $propertyDetails,
        mixed $addressLatitude,
        mixed $addressLongitude,
        mixed $preferredWorkerId = null,
    ): array {
        $normalizedDetails = $this->normalizePropertyDetails($propertyDetails);

        return [
            'propertyType' => $this->normalizePropertyType($propertyType),
            'propertyDetails' => [
                'bedrooms' => $normalizedDetails['bedrooms'],
                'rooms' => $normalizedDetails['rooms'],
                'bathrooms' => $normalizedDetails['bathrooms'],
                'living_room_size' => $normalizedDetails['living_room_size'],
            ],
            'addressLatitude' => $this->normalizeCoordinate($addressLatitude),
            'addressLongitude' => $this->normalizeCoordinate($addressLongitude),
            'preferredWorkerId' => $this->normalizePreferredWorkerId($preferredWorkerId),
        ];
    }

    /**
     * @param  array<string, mixed>  $propertyDetails
     * @return array<string, mixed>
     */
    public function normalizePropertyDetailsForStorage(array $propertyDetails): array
    {
        return array_filter(
            $this->normalizePropertyDetails($propertyDetails),
            static fn (mixed $value): bool => $value !== null
        );
    }

    /**
     * @param  array<string, mixed>  $propertyDetails
     * @return array{estimatedSqm: float, estimatedHours: float, sizeTier: string}
     */
    public function estimate(string $propertyType, array $propertyDetails): array
    {
        $normalizedPropertyType = $this->normalizePropertyType($propertyType);
        $normalizedDetails = $this->normalizePropertyDetails($propertyDetails);

        $bedrooms = $normalizedDetails['bedrooms'];
        $rooms = $normalizedDetails['rooms'];
        $bathrooms = $normalizedDetails['bathrooms'];
        $livingRoomSize = $normalizedDetails['living_room_size'];

        $baseSqm = $this->baseSqmByPropertyType($normalizedPropertyType);
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
        $normalizedInput = $this->pricingSnapshotInput(
            $propertyType,
            $propertyDetails,
            $addressLatitude,
            $addressLongitude,
            $preferredWorkerId
        );

        $estimation = $this->estimate($normalizedInput['propertyType'], $normalizedInput['propertyDetails']);

        $pricePerSqm = $this->pricePerSqmByPropertyType($normalizedInput['propertyType']);
        $basePrice = round(max(250.0, $estimation['estimatedSqm'] * $pricePerSqm), 2);

        $hasCoordinates = $normalizedInput['addressLatitude'] !== null && $normalizedInput['addressLongitude'] !== null;
        $travelFee = $hasCoordinates ? 150.0 : 200.0;

        $addonsTotal = $normalizedInput['preferredWorkerId'] !== null ? 100.0 : 0.0;

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

    private function normalizeCoordinate(mixed $coordinate): ?float
    {
        if (! is_numeric($coordinate)) {
            return null;
        }

        return round((float) $coordinate, 6);
    }

    private function normalizePreferredWorkerId(mixed $preferredWorkerId): ?int
    {
        if (! is_numeric($preferredWorkerId)) {
            return null;
        }

        $normalized = (int) $preferredWorkerId;

        return $normalized > 0 ? $normalized : null;
    }
}
