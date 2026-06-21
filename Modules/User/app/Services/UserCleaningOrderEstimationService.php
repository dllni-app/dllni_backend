<?php

declare(strict_types=1);

namespace Modules\User\Services;

use App\Models\CleaningFinancialSetting;
use App\Models\Worker;
use Illuminate\Support\Arr;
use InvalidArgumentException;
use Modules\Cleaning\Enums\ServiceCategory;
use Modules\Cleaning\Models\CleaningService;
use Modules\Cleaning\Models\ServicePricing;
use Modules\Cleaning\Services\CleaningPricingCalculator;

final class UserCleaningOrderEstimationService
{
    public const ALGORITHM_VERSION = '2026-06-11-v4';
    public const EVENT_ASSISTANCE_PROPERTY_TYPE = 'event_assistance';

    /**
     * @var array<int, string>
     */
    public const CLEANING_MODES = [
        'regular',
        'deep',
    ];

    /**
     * @var array<int, string>
     */
    public const PROPERTY_TYPES = [
        'apartment',
        'villa',
        'house',
        'office',
        'studio',
        self::EVENT_ASSISTANCE_PROPERTY_TYPE,
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

    /**
     * @var array<int, string>
     */
    public const EVENT_TYPES = [
        'family_dinner',
        'birthday',
        'large_gathering',
        'funeral',
        'other',
    ];

    /**
     * @var array<int, string>
     */
    private const ROOM_SIZE_BREAKDOWN_TYPES = [
        'bedroom',
        'bathroom',
        'kitchen',
        'living_room',
        'balcony',
        'corridor',
    ];

    public function __construct(
        private readonly CleaningPricingCalculator $pricingCalculator,
    ) {}

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

    public function isEventAssistanceType(string $propertyType): bool
    {
        return $this->normalizePropertyType($propertyType) === self::EVENT_ASSISTANCE_PROPERTY_TYPE;
    }

    /**
     * @param  array<string, mixed>  $propertyDetails
     * @return array{address: ?string, location_name: ?string, bedrooms: int, rooms: int, bathrooms: int, kitchens: int, balconies: int, living_room_size: string, cleaning_mode: string, room_size_breakdown: ?array<string, array{small:int, medium:int, large:int}>}
     */
    public function normalizePropertyDetails(array $propertyDetails): array
    {
        $address = Arr::has($propertyDetails, 'address')
            ? mb_trim((string) Arr::get($propertyDetails, 'address'))
            : null;

        $locationName = Arr::has($propertyDetails, 'location_name')
            ? mb_trim((string) Arr::get($propertyDetails, 'location_name'))
            : null;

        $normalizedBreakdown = $this->normalizeRoomSizeBreakdown(
            Arr::get($propertyDetails, 'room_size_breakdown')
        );
        $cleaningMode = $this->normalizeCleaningMode(Arr::get($propertyDetails, 'cleaning_mode'));

        if ($normalizedBreakdown !== null) {
            $livingRoomSize = $this->deriveLivingRoomSizeFromBreakdown($normalizedBreakdown['living_room']);
            $bedrooms = $this->sumRoomTypeBuckets($normalizedBreakdown['bedroom'])
                + $this->sumRoomTypeBuckets($normalizedBreakdown['bathroom'])
                + $this->sumRoomTypeBuckets($normalizedBreakdown['kitchen'])
                + $this->sumRoomTypeBuckets($normalizedBreakdown['living_room'])
                + $this->sumRoomTypeBuckets($normalizedBreakdown['balcony'])
                + $this->sumRoomTypeBuckets($normalizedBreakdown['corridor']);
            $rooms = $this->sumRoomTypeBuckets($normalizedBreakdown['bedroom']);
            $bathrooms = $this->sumRoomTypeBuckets($normalizedBreakdown['bathroom']);
            $kitchens = $this->sumRoomTypeBuckets($normalizedBreakdown['kitchen']);
            $balconies = $this->sumRoomTypeBuckets($normalizedBreakdown['balcony']);
        } else {
            $livingRoomSize = mb_strtolower((string) Arr::get($propertyDetails, 'living_room_size', 'medium'));
            if (! in_array($livingRoomSize, self::LIVING_ROOM_SIZES, true)) {
                $livingRoomSize = 'medium';
            }

            $bedrooms = max(0, (int) Arr::get($propertyDetails, 'bedrooms', 0));
            $rooms = max(0, (int) Arr::get($propertyDetails, 'rooms', 0));
            $bathrooms = max(0, (int) Arr::get($propertyDetails, 'bathrooms', 0));
            $kitchens = max(0, (int) Arr::get($propertyDetails, 'kitchens', Arr::get($propertyDetails, 'kitchen_included') ? 1 : 0));
            $balconies = max(0, (int) Arr::get($propertyDetails, 'balconies', 0));
        }

        return [
            'address' => $address !== '' ? $address : null,
            'location_name' => $locationName !== '' ? $locationName : null,
            'bedrooms' => $bedrooms,
            'rooms' => $rooms,
            'bathrooms' => $bathrooms,
            'kitchens' => $kitchens,
            'balconies' => $balconies,
            'living_room_size' => $livingRoomSize,
            'cleaning_mode' => $cleaningMode,
            'room_size_breakdown' => $normalizedBreakdown,
        ];
    }

    /**
     * @param  array<string, mixed>  $propertyDetails
     * @return array{propertyType: string, propertyDetails: array<string, mixed>, addressLatitude: ?float, addressLongitude: ?float, preferredWorkerId: ?int, serviceIds: array<int, int>}
     */
    public function pricingSnapshotInput(
        string $propertyType,
        array $propertyDetails,
        mixed $addressLatitude,
        mixed $addressLongitude,
        mixed $preferredWorkerId = null,
        ?array $serviceIds = null,
    ): array {
        $normalizedPropertyType = $this->normalizePropertyType($propertyType);
        $normalizedDetails = $this->isEventAssistanceType($normalizedPropertyType)
            ? $this->normalizeEventPropertyDetailsForStorage($propertyDetails)
            : $this->normalizePropertyDetails($propertyDetails);

        return [
            'propertyType' => $normalizedPropertyType,
            'propertyDetails' => $normalizedDetails,
            'addressLatitude' => $this->normalizeCoordinate($addressLatitude),
            'addressLongitude' => $this->normalizeCoordinate($addressLongitude),
            'preferredWorkerId' => $this->normalizePreferredWorkerId($preferredWorkerId),
            'serviceIds' => $this->normalizeServiceIds($serviceIds ?? []),
        ];
    }

    /**
     * @param  array<string, mixed>  $propertyDetails
     * @return array<string, mixed>
     */
    public function normalizePropertyDetailsForStorage(string $propertyType, array $propertyDetails): array
    {
        if ($this->isEventAssistanceType($propertyType)) {
            return $this->normalizeEventPropertyDetailsForStorage($propertyDetails);
        }

        return array_filter(
            $this->normalizePropertyDetails($propertyDetails),
            static fn (mixed $value): bool => $value !== null
        );
    }

    /**
     * @param  array<string, mixed>  $propertyDetails
     * @return array<string, mixed>
     */
    public function estimate(string $propertyType, array $propertyDetails, ?array $serviceIds = null): array
    {
        $normalizedPropertyType = $this->normalizePropertyType($propertyType);

        if ($this->isEventAssistanceType($normalizedPropertyType)) {
            $normalizedDetails = $this->normalizeEventPropertyDetailsForStorage($propertyDetails);
            $guestCount = (int) ($normalizedDetails['guest_count'] ?? 0);
            $venueType = (string) ($normalizedDetails['venue_type'] ?? 'apartment');
            $eventType = (string) ($normalizedDetails['event_type'] ?? 'other');
            $hours = (float) ($normalizedDetails['hours'] ?? 1.0);

            $estimatedSqm = max(25.0, $guestCount * 2.0);
            $estimatedHours = max(1.0, $this->roundToHalfHour($hours));
            $recommendedTeamSize = $this->suggestedEventTeamSize($guestCount);

            return [
                'estimatedSqm' => $estimatedSqm,
                'estimatedHours' => $estimatedHours,
                'sizeTier' => $this->sizeTier($estimatedSqm),
                'isEventAssistance' => true,
                'recommendation' => [
                    'eventType' => $eventType,
                    'guestCount' => $guestCount,
                    'venueType' => $venueType,
                    'customService' => $normalizedDetails['custom_service'] ?? null,
                    'hours' => $estimatedHours,
                    'suggestedTeamSize' => $recommendedTeamSize,
                ],
            ];
        }

        $normalizedDetails = $this->normalizePropertyDetails($propertyDetails);
        $cleaningModeFactor = $this->cleaningModeFactor((string) $normalizedDetails['cleaning_mode']);

        $bedrooms = $normalizedDetails['bedrooms'];
        $rooms = $normalizedDetails['rooms'];
        $bathrooms = $normalizedDetails['bathrooms'];
        $kitchens = $normalizedDetails['kitchens'];
        $balconies = $normalizedDetails['balconies'];
        $livingRoomSize = $normalizedDetails['living_room_size'];

        $baseSqm = $this->baseSqmByPropertyType($normalizedPropertyType);
        $livingRoomSqm = $this->livingRoomSqmAdjustment($livingRoomSize);

        $estimatedSqm = max(25.0, $baseSqm + ($bedrooms * 18.0) + ($rooms * 8.0) + ($bathrooms * 6.0) + ($kitchens * 10.0) + ($balconies * 4.0) + $livingRoomSqm);

        $rawHours = ($estimatedSqm / 35.0)
            + ($bathrooms * 0.25)
            + ($kitchens * 0.20)
            + ($balconies * 0.10)
            + ($livingRoomSize === 'large' ? 0.25 : 0.0)
            + ($livingRoomSize === 'very_large' ? 0.50 : 0.0);

        $estimatedHours = max(1.0, $this->roundToHalfHour($rawHours));
        $estimatedHours = $this->roundToHalfHour($estimatedHours * $cleaningModeFactor);

        return [
            'estimatedSqm' => $estimatedSqm,
            'estimatedHours' => $estimatedHours,
            'sizeTier' => $this->sizeTier($estimatedSqm),
            'isEventAssistance' => false,
            'recommendation' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $propertyDetails
     * @return array<string, mixed>
     */
    public function price(
        string $propertyType,
        array $propertyDetails,
        mixed $addressLatitude,
        mixed $addressLongitude,
        mixed $preferredWorkerId = null,
        ?array $serviceIds = null,
    ): array {
        $normalizedInput = $this->pricingSnapshotInput(
            $propertyType,
            $propertyDetails,
            $addressLatitude,
            $addressLongitude,
            $preferredWorkerId,
            $serviceIds,
        );

        if ($this->isEventAssistanceType($normalizedInput['propertyType'])) {
            $hourlyRate = $this->eventOrderHourlyRate();
            $eventHours = max(1.0, $this->roundToHalfHour((float) ($normalizedInput['propertyDetails']['hours'] ?? 1.0)));
            $basePrice = round($hourlyRate * $eventHours, 2);
            $lines = [];
            $addonsTotal = 0.0;
            $estimation = $this->estimate(
                $normalizedInput['propertyType'],
                $normalizedInput['propertyDetails'],
            );
        } else {
            $estimation = $this->estimate($normalizedInput['propertyType'], $normalizedInput['propertyDetails']);
            $pricePerSqm = $this->pricePerSqmByPropertyType($normalizedInput['propertyType']);
            $basePrice = round(max(250.0, $estimation['estimatedSqm'] * $pricePerSqm), 2);
            $cleaningModeFactor = $this->cleaningModeFactor((string) ($normalizedInput['propertyDetails']['cleaning_mode'] ?? 'regular'));
            $basePrice = round($basePrice * $cleaningModeFactor, 2);
            $livingRoomSize = (string) ($normalizedInput['propertyDetails']['living_room_size'] ?? 'medium');
            $lines = $this->resolveRegularCleaningPricingLines(
                $normalizedInput['serviceIds'],
                $normalizedInput['propertyType'],
                $livingRoomSize,
                (float) $estimation['estimatedSqm'],
            );
            $addonsTotal = round(array_sum(array_map(
                static fn (array $line): float => (float) $line['totalPrice'],
                $lines
            )), 2);
        }

        if ($normalizedInput['preferredWorkerId'] === null) {
            $pricing = $this->pricingCalculator->provisional($basePrice, $addonsTotal);
        } else {
            $worker = Worker::query()->find($normalizedInput['preferredWorkerId']);
            if (! $worker) {
                throw new InvalidArgumentException('Preferred worker is not available.');
            }

            $pricing = $this->pricingCalculator->finalizedForWorker(
                $basePrice,
                $addonsTotal,
                $normalizedInput['addressLatitude'],
                $normalizedInput['addressLongitude'],
                $worker,
            );
        }

        return [
            'basePrice' => $basePrice,
            'addonsTotal' => $addonsTotal,
            'travelFee' => $pricing['travelFee'],
            'distanceKm' => $pricing['distanceKm'],
            'adminMargin' => $pricing['adminMargin'],
            'isPricingFinal' => $pricing['isPricingFinal'],
            'totalPrice' => $pricing['totalPrice'],
            'currency' => (string) config('app.currency', 'SYP'),
            'serviceLines' => $lines,
            'eventHourlyRate' => isset($hourlyRate) ? $hourlyRate : null,
            'eventHours' => isset($eventHours) ? $eventHours : null,
            'recommendation' => $estimation['recommendation'] ?? null,
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

    private function normalizeCleaningMode(mixed $cleaningMode): string
    {
        $normalized = mb_strtolower(mb_trim((string) ($cleaningMode ?? 'regular')));

        if (! in_array($normalized, self::CLEANING_MODES, true)) {
            return 'regular';
        }

        return $normalized;
    }

    private function cleaningModeFactor(string $cleaningMode): float
    {
        return $this->normalizeCleaningMode($cleaningMode) === 'deep' ? 5.0 : 1.0;
    }

    /**
     * @param  array<int, mixed>  $serviceIds
     * @return array<int, int>
     */
    private function normalizeServiceIds(array $serviceIds): array
    {
        $normalized = [];

        foreach ($serviceIds as $serviceId) {
            if (! is_numeric($serviceId)) {
                continue;
            }

            $id = (int) $serviceId;

            if ($id <= 0) {
                continue;
            }

            if (! in_array($id, $normalized, true)) {
                $normalized[] = $id;
            }
        }

        return $normalized;
    }

    private function eventOrderHourlyRate(): float
    {
        $ratePerThirtyMinutes = (float) (CleaningFinancialSetting::query()->value('extension_rate_per_30_minutes') ?? 0);

        if ($ratePerThirtyMinutes <= 0) {
            throw new InvalidArgumentException('Event assistance hourly rate is not configured.');
        }

        return round($ratePerThirtyMinutes * 2, 2);
    }

    /**
     * @param  array<int, int>  $serviceIds
     * @return array<int, array{cleaningServiceId:int,name:string,description:?string,price:float,quantity:float,unitPrice:float,totalPrice:float,minHours:float}>
     */
    private function resolveRegularCleaningPricingLines(
        array $serviceIds,
        string $propertyType,
        string $livingRoomSize,
        float $estimatedSqm,
    ): array {
        if ($serviceIds === []) {
            return [];
        }

        $services = CleaningService::query()
            ->whereIn('id', $serviceIds)
            ->where('is_active', true)
            ->where('category', ServiceCategory::Cleaning->value)
            ->with(['pricing' => fn ($query) => $query->orderBy('id')])
            ->get()
            ->keyBy('id');

        if ($services->count() !== count($serviceIds)) {
            throw new InvalidArgumentException('One or more selected regular cleaning services are invalid.');
        }

        $lines = [];

        foreach ($serviceIds as $serviceId) {
            $service = $services->get($serviceId);

            if (! $service instanceof CleaningService) {
                throw new InvalidArgumentException('One or more selected regular cleaning services are invalid.');
            }

            $pricing = $service->pricing->first(function (ServicePricing $row) use ($propertyType, $livingRoomSize): bool {
                return $row->property_type === $propertyType && $row->living_room_size === $livingRoomSize;
            });

            if (! $pricing instanceof ServicePricing) {
                $pricing = $service->pricing->first(function (ServicePricing $row) use ($propertyType): bool {
                    return $row->property_type === $propertyType && $row->living_room_size === null;
                });
            }

            if (! $pricing instanceof ServicePricing) {
                $pricing = $service->pricing->first(function (ServicePricing $row) use ($propertyType): bool {
                    return $row->property_type === $propertyType;
                });
            }

            if (! $pricing instanceof ServicePricing) {
                $pricing = $service->pricing->first();
            }

            if (! $pricing instanceof ServicePricing) {
                throw new InvalidArgumentException("No pricing configured for regular cleaning service [{$service->name}].");
            }

            $basePrice = round((float) $pricing->base_price, 2);
            $sqmPrice = $pricing->price_per_sqm !== null
                ? round((float) $pricing->price_per_sqm * $estimatedSqm, 2)
                : null;
            $servicePrice = (float) ($service->price ?? 0);
            $unitPrice = $servicePrice > 0
                ? round($servicePrice, 2)
                : ($sqmPrice !== null ? max($basePrice, $sqmPrice) : $basePrice);
            $unitPrice = round($unitPrice, 2);

            $lines[] = [
                'cleaningServiceId' => (int) $service->id,
                'name' => (string) $service->name,
                'description' => $service->description,
                'price' => $unitPrice,
                'quantity' => 1.0,
                'unitPrice' => $unitPrice,
                'totalPrice' => $unitPrice,
                'minHours' => round((float) ($pricing->min_hours ?? 0), 2),
            ];
        }

        return $lines;
    }

    /**
     * @param  array<string, mixed>  $propertyDetails
     * @return array<string, mixed>
     */
    private function normalizeEventPropertyDetailsForStorage(array $propertyDetails): array
    {
        $eventType = mb_strtolower((string) Arr::get($propertyDetails, 'eventType', Arr::get($propertyDetails, 'event_type', 'other')));
        if (! in_array($eventType, self::EVENT_TYPES, true)) {
            $eventType = 'other';
        }

        $venueType = $this->normalizePropertyType((string) Arr::get($propertyDetails, 'venueType', Arr::get($propertyDetails, 'venue_type', 'apartment')));
        if ($venueType === self::EVENT_ASSISTANCE_PROPERTY_TYPE) {
            $venueType = 'apartment';
        }

        $address = Arr::has($propertyDetails, 'address')
            ? mb_trim((string) Arr::get($propertyDetails, 'address'))
            : null;
        $locationName = Arr::has($propertyDetails, 'location_name')
            ? mb_trim((string) Arr::get($propertyDetails, 'location_name'))
            : null;
        $specialRequirement = Arr::has($propertyDetails, 'specialRequirement')
            ? mb_trim((string) Arr::get($propertyDetails, 'specialRequirement'))
            : null;
        $customService = Arr::has($propertyDetails, 'customService')
            ? mb_trim((string) Arr::get($propertyDetails, 'customService'))
            : mb_trim((string) Arr::get($propertyDetails, 'custom_service', ''));
        $notes = Arr::has($propertyDetails, 'notes')
            ? mb_trim((string) Arr::get($propertyDetails, 'notes'))
            : null;
        $hours = is_numeric(Arr::get($propertyDetails, 'hours'))
            ? $this->roundToHalfHour((float) Arr::get($propertyDetails, 'hours'))
            : 1.0;

        return array_filter([
            'address' => $address !== '' ? $address : null,
            'location_name' => $locationName !== '' ? $locationName : null,
            'event_type' => $eventType,
            'guest_count' => max(0, (int) Arr::get($propertyDetails, 'guestCount', Arr::get($propertyDetails, 'guest_count', 0))),
            'venue_type' => $venueType,
            'custom_service' => $customService !== '' ? $customService : null,
            'hours' => max(1.0, min(24.0, $hours)),
            'special_requirement' => $specialRequirement !== '' ? $specialRequirement : null,
            'notes' => $notes !== '' ? $notes : null,
        ], static fn (mixed $value): bool => $value !== null);
    }

    private function suggestedEventTeamSize(int $guestCount): int
    {
        return max(1, (int) ceil($guestCount / 10));
    }

    /**
     * @param  mixed  $value
     * @return array<string, array{small:int, medium:int, large:int}>|null
     */
    private function normalizeRoomSizeBreakdown(mixed $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }

        $buckets = ['small', 'medium', 'large'];
        $normalized = [];

        foreach (self::ROOM_SIZE_BREAKDOWN_TYPES as $type) {
            $roomTypeCounts = Arr::get($value, $type);
            if (! is_array($roomTypeCounts)) {
                $roomTypeCounts = [];
            }

            $normalized[$type] = [];
            foreach ($buckets as $bucket) {
                $normalized[$type][$bucket] = max(0, (int) Arr::get($roomTypeCounts, $bucket, 0));
            }
        }

        return $normalized;
    }

    /**
     * @param  array{small:int, medium:int, large:int}  $roomTypeBuckets
     */
    private function deriveLivingRoomSizeFromBreakdown(array $roomTypeBuckets): string
    {
        if ($roomTypeBuckets['large'] > 0) {
            return 'large';
        }

        if ($roomTypeBuckets['medium'] > 0) {
            return 'medium';
        }

        return 'small';
    }

    /**
     * @param  array{small:int, medium:int, large:int}  $roomTypeBuckets
     */
    private function sumRoomTypeBuckets(array $roomTypeBuckets): int
    {
        return $roomTypeBuckets['small'] + $roomTypeBuckets['medium'] + $roomTypeBuckets['large'];
    }
}
