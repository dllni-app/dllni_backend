<?php

declare(strict_types=1);

namespace Modules\User\Services;

use App\Models\CleaningFinancialSetting;
use App\Models\Worker;
use Illuminate\Support\Arr;
use InvalidArgumentException;
use Modules\Cleaning\Services\CleaningPricingCalculator;
use Modules\Cleaning\Support\CleaningFinancialDefaults;

final class UserCleaningOrderEstimationService
{
    public const ALGORITHM_VERSION = '2026-07-01-syrian-cash-v1';
    public const EVENT_ASSISTANCE_PROPERTY_TYPE = 'event_assistance';
    public const CLEANING_MODES = ['regular', 'deep'];
    public const PROPERTY_TYPES = ['apartment', 'villa', 'house', 'office', 'studio', self::EVENT_ASSISTANCE_PROPERTY_TYPE];
    public const LIVING_ROOM_SIZES = ['small', 'medium', 'large', 'very_large'];
    public const EVENT_TYPES = ['family_dinner', 'birthday', 'large_gathering', 'funeral', 'other'];
    private const ROOM_SIZE_BREAKDOWN_TYPES = CleaningFinancialDefaults::ROOM_TYPES;

    public function __construct(private readonly CleaningPricingCalculator $pricingCalculator) {}

    public function algorithmVersion(): string { return self::ALGORITHM_VERSION; }

    public function normalizePropertyType(string $propertyType): string
    {
        $normalized = mb_strtolower(mb_trim($propertyType));
        return in_array($normalized, self::PROPERTY_TYPES, true) ? $normalized : 'apartment';
    }

    public function isEventAssistanceType(string $propertyType): bool
    {
        return $this->normalizePropertyType($propertyType) === self::EVENT_ASSISTANCE_PROPERTY_TYPE;
    }

    public function normalizePropertyDetails(array $propertyDetails): array
    {
        $breakdown = $this->normalizeRoomSizeBreakdown(Arr::get($propertyDetails, 'room_size_breakdown'));
        $cleaningMode = $this->normalizeCleaningMode(Arr::get($propertyDetails, 'cleaning_mode'));
        $address = Arr::has($propertyDetails, 'address') ? mb_trim((string) Arr::get($propertyDetails, 'address')) : null;
        $locationName = Arr::has($propertyDetails, 'location_name') ? mb_trim((string) Arr::get($propertyDetails, 'location_name')) : null;

        if ($breakdown !== null) {
            $livingRoomSize = $this->deriveLivingRoomSizeFromBreakdown($breakdown['living_room']);
            $rooms = $this->sumRoomTypeBuckets($breakdown['bedroom']);
            $bathrooms = $this->sumRoomTypeBuckets($breakdown['bathroom']);
            $toilets = $this->sumRoomTypeBuckets($breakdown['toilet']);
            $kitchens = $this->sumRoomTypeBuckets($breakdown['kitchen']);
            $balconies = $this->sumRoomTypeBuckets($breakdown['balcony']);
            $bedrooms = $this->sumAllRoomBuckets($breakdown);
        } else {
            $livingRoomSize = mb_strtolower((string) Arr::get($propertyDetails, 'living_room_size', 'medium'));
            $livingRoomSize = in_array($livingRoomSize, self::LIVING_ROOM_SIZES, true) ? $livingRoomSize : 'medium';
            $rooms = max(0, (int) Arr::get($propertyDetails, 'rooms', Arr::get($propertyDetails, 'bedrooms', 0)));
            $bathrooms = max(0, (int) Arr::get($propertyDetails, 'bathrooms', 0));
            $toilets = max(0, (int) Arr::get($propertyDetails, 'toilets', 0));
            $kitchens = max(0, (int) Arr::get($propertyDetails, 'kitchens', Arr::get($propertyDetails, 'kitchen_included') ? 1 : 0));
            $balconies = max(0, (int) Arr::get($propertyDetails, 'balconies', 0));
            $bedrooms = $rooms + $bathrooms + $toilets + $kitchens + $balconies + 1;
        }

        return [
            'address' => $address !== '' ? $address : null,
            'location_name' => $locationName !== '' ? $locationName : null,
            'bedrooms' => $bedrooms,
            'rooms' => $rooms,
            'bathrooms' => $bathrooms,
            'toilets' => $toilets,
            'kitchens' => $kitchens,
            'balconies' => $balconies,
            'living_room_size' => $livingRoomSize,
            'cleaning_mode' => $cleaningMode,
            'room_size_breakdown' => $breakdown,
        ];
    }

    public function pricingSnapshotInput(string $propertyType, array $propertyDetails, mixed $addressLatitude, mixed $addressLongitude, mixed $preferredWorkerId = null, ?array $serviceIds = null): array
    {
        $normalizedPropertyType = $this->normalizePropertyType($propertyType);
        return [
            'propertyType' => $normalizedPropertyType,
            'propertyDetails' => $this->isEventAssistanceType($normalizedPropertyType) ? $this->normalizeEventPropertyDetailsForStorage($propertyDetails) : $this->normalizePropertyDetails($propertyDetails),
            'addressLatitude' => $this->normalizeCoordinate($addressLatitude),
            'addressLongitude' => $this->normalizeCoordinate($addressLongitude),
            'preferredWorkerId' => $this->normalizePreferredWorkerId($preferredWorkerId),
        ];
    }

    public function normalizePropertyDetailsForStorage(string $propertyType, array $propertyDetails): array
    {
        if ($this->isEventAssistanceType($propertyType)) {
            return $this->normalizeEventPropertyDetailsForStorage($propertyDetails);
        }
        return array_filter($this->normalizePropertyDetails($propertyDetails), static fn (mixed $value): bool => $value !== null);
    }

    public function estimate(string $propertyType, array $propertyDetails, ?array $serviceIds = null): array
    {
        $normalizedPropertyType = $this->normalizePropertyType($propertyType);
        if ($this->isEventAssistanceType($normalizedPropertyType)) {
            $details = $this->normalizeEventPropertyDetailsForStorage($propertyDetails);
            $guestCount = (int) ($details['guest_count'] ?? 0);
            $hours = max(1.0, $this->roundToHalfHour((float) ($details['hours'] ?? 1.0)));
            $estimatedSqm = max(25.0, $guestCount * 2.0);
            return [
                'estimatedSqm' => $estimatedSqm,
                'estimatedHours' => $hours,
                'sizeTier' => $this->sizeTier($estimatedSqm),
                'isEventAssistance' => true,
                'recommendation' => [
                    'eventType' => (string) ($details['event_type'] ?? 'other'),
                    'guestCount' => $guestCount,
                    'venueType' => (string) ($details['venue_type'] ?? 'apartment'),
                    'customService' => $details['custom_service'] ?? null,
                    'hours' => $hours,
                    'suggestedTeamSize' => $this->suggestedEventTeamSize($guestCount),
                ],
            ];
        }

        $calculation = $this->calculateRegularCleaningFromSettings($this->normalizePropertyDetails($propertyDetails), $normalizedPropertyType);
        return [
            'estimatedSqm' => $calculation['estimatedSqm'],
            'estimatedHours' => $calculation['estimatedHours'],
            'sizeTier' => $this->sizeTier((float) $calculation['estimatedSqm']),
            'isEventAssistance' => false,
            'recommendation' => null,
            'estimatedRawMinutes' => $calculation['estimatedRawMinutes'],
            'setupBufferMinutes' => $calculation['setupBufferMinutes'],
        ];
    }

    public function price(string $propertyType, array $propertyDetails, mixed $addressLatitude, mixed $addressLongitude, mixed $preferredWorkerId = null, ?array $serviceIds = null): array
    {
        $input = $this->pricingSnapshotInput($propertyType, $propertyDetails, $addressLatitude, $addressLongitude, $preferredWorkerId, $serviceIds);
        $regularCalculation = null;

        if ($this->isEventAssistanceType($input['propertyType'])) {
            $hourlyRate = $this->eventOrderHourlyRate();
            $eventHours = max(1.0, $this->roundToHalfHour((float) ($input['propertyDetails']['hours'] ?? 1.0)));
            $basePrice = $this->pricingCalculator->roundMoney($hourlyRate * $eventHours);
            $lines = [];
            $addonsTotal = 0.0;
            $estimation = $this->estimate($input['propertyType'], $input['propertyDetails']);
        } else {
            $regularCalculation = $this->calculateRegularCleaningFromSettings($input['propertyDetails'], $input['propertyType']);
            $basePrice = $this->pricingCalculator->roundMoney((float) $regularCalculation['basePrice']);
            $estimation = ['recommendation' => null];
            $lines = [];
            $addonsTotal = 0.0;
        }

        if ($input['preferredWorkerId'] === null) {
            $pricing = $this->pricingCalculator->provisional($basePrice, $addonsTotal);
        } else {
            $worker = Worker::query()->find($input['preferredWorkerId']);
            if (! $worker) {
                throw new InvalidArgumentException('Selected worker is not available.');
            }
            $pricing = $this->pricingCalculator->finalizedForWorker($basePrice, $addonsTotal, $input['addressLatitude'], $input['addressLongitude'], $worker);
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
            'roomPricingLines' => $regularCalculation['roomPricingLines'] ?? [],
            'pricingAlgorithm' => $regularCalculation !== null ? [
                'baseUnitPrice' => $regularCalculation['baseUnitPrice'],
                'deepCleaningMultiplier' => $regularCalculation['deepCleaningMultiplier'],
                'areaMarginMultiplier' => $regularCalculation['areaMarginMultiplier'],
                'setupBufferMinutes' => $regularCalculation['setupBufferMinutes'],
                'unitTotal' => $regularCalculation['unitTotal'],
                'modeMultiplier' => $regularCalculation['modeMultiplier'],
            ] : null,
            'eventHourlyRate' => isset($hourlyRate) ? $hourlyRate : null,
            'eventHours' => isset($eventHours) ? $eventHours : null,
            'recommendation' => $estimation['recommendation'] ?? null,
        ];
    }

    private function calculateRegularCleaningFromSettings(array $normalizedDetails, string $propertyType = 'apartment'): array
    {
        $setting = CleaningFinancialSetting::query()->first();
        if (! $this->hasDatabaseRoomPricingAlgorithm($setting)) {
            return $this->calculateRegularCleaningWithLegacyDefaults($normalizedDetails, $propertyType);
        }

        $baseUnitPrice = max(0.0, (float) ($setting?->cleaning_base_unit_price ?? CleaningFinancialDefaults::BASE_UNIT_PRICE));
        $deepMultiplier = max(1.0, (float) ($setting?->cleaning_deep_multiplier ?? CleaningFinancialDefaults::DEEP_CLEANING_MULTIPLIER));
        $areaMarginMultiplier = max(1.0, (float) ($setting?->cleaning_area_margin_multiplier ?? CleaningFinancialDefaults::AREA_MARGIN_MULTIPLIER));
        $setupBufferMinutes = max(0, (int) ($setting?->cleaning_setup_buffer_minutes ?? CleaningFinancialDefaults::SETUP_BUFFER_MINUTES));
        $roomSizeRanges = $this->normalizedRoomSizeRanges($setting?->cleaning_room_size_ranges);
        $roomPricingUnits = $this->normalizedRoomPricingUnits($setting?->cleaning_room_pricing_units);
        $roomTimeMinutes = $this->normalizedRoomTimeMinutes($setting?->cleaning_room_time_minutes);
        $roomBreakdown = is_array($normalizedDetails['room_size_breakdown'] ?? null) ? $this->normalizeRoomSizeBreakdown($normalizedDetails['room_size_breakdown']) : $this->legacyDetailsToRoomBreakdown($normalizedDetails);
        $cleaningMode = $this->normalizeCleaningMode($normalizedDetails['cleaning_mode'] ?? 'regular');
        $modeMultiplier = $cleaningMode === 'deep' ? $deepMultiplier : 1.0;
        $minuteMode = $cleaningMode === 'deep' ? 'deep' : 'regular';
        $rawSqm = 0.0;
        $unitTotal = 0.0;
        $rawMinutes = 0;
        $basePrice = 0.0;
        $lines = [];

        foreach (self::ROOM_SIZE_BREAKDOWN_TYPES as $roomType) {
            foreach (CleaningFinancialDefaults::ROOM_SIZES as $roomSize) {
                $count = max(0, (int) ($roomBreakdown[$roomType][$roomSize] ?? 0));
                if ($count <= 0) {
                    continue;
                }
                $averageSqm = (float) ($roomSizeRanges[$roomType][$roomSize]['average'] ?? 0.0);
                $unitCount = (float) ($roomPricingUnits[$roomType][$roomSize] ?? 0.0);
                $minutesPerRoom = (int) ($roomTimeMinutes[$roomType][$roomSize][$minuteMode] ?? 0);
                $unitPrice = $this->pricingCalculator->roundMoney($baseUnitPrice * $unitCount * $modeMultiplier);
                $lineTotal = $this->pricingCalculator->roundMoney($unitPrice * $count);
                $lineSqm = round($averageSqm * $count, 2);
                $lineMinutes = $minutesPerRoom * $count;
                $rawSqm += $lineSqm;
                $unitTotal += $unitCount * $count;
                $rawMinutes += $lineMinutes;
                $basePrice += $lineTotal;
                $lines[] = ['roomType' => $roomType, 'roomSize' => $roomSize, 'count' => $count, 'unitCount' => round($unitCount, 2), 'baseUnitPrice' => $baseUnitPrice, 'modeMultiplier' => $modeMultiplier, 'unitPrice' => $unitPrice, 'totalPrice' => $lineTotal, 'averageSqm' => $averageSqm, 'totalSqm' => $lineSqm, 'minutesPerRoom' => $minutesPerRoom, 'totalMinutes' => $lineMinutes];
            }
        }

        $estimatedSqm = round(max(25.0, $rawSqm * $areaMarginMultiplier), 2);
        $estimatedMinutesWithBuffer = $rawMinutes + $setupBufferMinutes;
        return ['basePrice' => $this->pricingCalculator->roundMoney($basePrice), 'estimatedSqm' => $estimatedSqm, 'estimatedHours' => max(1.0, $this->roundToHalfHour($estimatedMinutesWithBuffer / 60)), 'estimatedRawMinutes' => $rawMinutes, 'estimatedMinutesWithBuffer' => $estimatedMinutesWithBuffer, 'setupBufferMinutes' => $setupBufferMinutes, 'baseUnitPrice' => $baseUnitPrice, 'deepCleaningMultiplier' => $deepMultiplier, 'areaMarginMultiplier' => $areaMarginMultiplier, 'unitTotal' => round($unitTotal, 2), 'modeMultiplier' => $modeMultiplier, 'roomPricingLines' => $lines];
    }

    private function hasDatabaseRoomPricingAlgorithm(?CleaningFinancialSetting $setting): bool
    {
        return $setting instanceof CleaningFinancialSetting && is_array($setting->cleaning_room_size_ranges) && is_array($setting->cleaning_room_pricing_units) && is_array($setting->cleaning_room_time_minutes);
    }

    private function calculateRegularCleaningWithLegacyDefaults(array $details, string $propertyType): array
    {
        $factor = $this->normalizeCleaningMode((string) ($details['cleaning_mode'] ?? 'regular')) === 'deep' ? 5.0 : 1.0;
        $bedrooms = (int) ($details['bedrooms'] ?? 0);
        $rooms = (int) ($details['rooms'] ?? 0);
        $bathrooms = (int) ($details['bathrooms'] ?? 0);
        $kitchens = (int) ($details['kitchens'] ?? 0);
        $balconies = (int) ($details['balconies'] ?? 0);
        $livingRoomSize = (string) ($details['living_room_size'] ?? 'medium');
        $estimatedSqm = max(25.0, $this->baseSqmByPropertyTypeLegacy($propertyType) + ($bedrooms * 18.0) + ($rooms * 8.0) + ($bathrooms * 6.0) + ($kitchens * 10.0) + ($balconies * 4.0) + $this->livingRoomSqmAdjustmentLegacy($livingRoomSize));
        $rawHours = ($estimatedSqm / 35.0) + ($bathrooms * 0.25) + ($kitchens * 0.20) + ($balconies * 0.10) + ($livingRoomSize === 'large' ? 0.25 : 0.0) + ($livingRoomSize === 'very_large' ? 0.50 : 0.0);
        $estimatedHours = $this->roundToHalfHour(max(1.0, $this->roundToHalfHour($rawHours)) * $factor);
        $basePrice = $this->pricingCalculator->roundMoney(max(250.0, $estimatedSqm * $this->pricePerSqmByPropertyTypeLegacy($propertyType)) * $factor);
        return ['basePrice' => $basePrice, 'estimatedSqm' => $estimatedSqm, 'estimatedHours' => $estimatedHours, 'estimatedRawMinutes' => (int) round($estimatedHours * 60), 'estimatedMinutesWithBuffer' => (int) round($estimatedHours * 60), 'setupBufferMinutes' => 0, 'baseUnitPrice' => null, 'deepCleaningMultiplier' => $factor, 'areaMarginMultiplier' => null, 'unitTotal' => 0.0, 'modeMultiplier' => $factor, 'roomPricingLines' => []];
    }

    private function normalizeEventPropertyDetailsForStorage(array $propertyDetails): array
    {
        $eventType = mb_strtolower((string) Arr::get($propertyDetails, 'eventType', Arr::get($propertyDetails, 'event_type', 'other')));
        $eventType = in_array($eventType, self::EVENT_TYPES, true) ? $eventType : 'other';
        $venueType = $this->normalizePropertyType((string) Arr::get($propertyDetails, 'venueType', Arr::get($propertyDetails, 'venue_type', 'apartment')));
        $venueType = $venueType === self::EVENT_ASSISTANCE_PROPERTY_TYPE ? 'apartment' : $venueType;
        $customService = Arr::has($propertyDetails, 'customService') ? mb_trim((string) Arr::get($propertyDetails, 'customService')) : mb_trim((string) Arr::get($propertyDetails, 'custom_service', ''));
        $hours = is_numeric(Arr::get($propertyDetails, 'hours')) ? $this->roundToHalfHour((float) Arr::get($propertyDetails, 'hours')) : 1.0;
        return array_filter(['address' => $this->nullableTrim($propertyDetails, 'address'), 'location_name' => $this->nullableTrim($propertyDetails, 'location_name'), 'event_type' => $eventType, 'guest_count' => max(0, (int) Arr::get($propertyDetails, 'guestCount', Arr::get($propertyDetails, 'guest_count', 0))), 'venue_type' => $venueType, 'custom_service' => $customService !== '' ? $customService : null, 'hours' => max(1.0, min(24.0, $hours)), 'special_requirement' => $this->nullableTrim($propertyDetails, 'specialRequirement'), 'notes' => $this->nullableTrim($propertyDetails, 'notes')], static fn (mixed $value): bool => $value !== null);
    }

    private function nullableTrim(array $data, string $key): ?string
    {
        if (! Arr::has($data, $key)) { return null; }
        $value = mb_trim((string) Arr::get($data, $key));
        return $value !== '' ? $value : null;
    }

    private function normalizeRoomSizeBreakdown(mixed $value): ?array
    {
        if (! is_array($value)) { return null; }
        $normalized = [];
        foreach (self::ROOM_SIZE_BREAKDOWN_TYPES as $type) {
            $roomTypeCounts = Arr::get($value, $type);
            $roomTypeCounts = is_array($roomTypeCounts) ? $roomTypeCounts : [];
            foreach (CleaningFinancialDefaults::ROOM_SIZES as $bucket) {
                $normalized[$type][$bucket] = max(0, (int) Arr::get($roomTypeCounts, $bucket, 0));
            }
        }
        return $normalized;
    }

    private function deriveLivingRoomSizeFromBreakdown(array $buckets): string { return $buckets['large'] > 0 ? 'large' : ($buckets['medium'] > 0 ? 'medium' : 'small'); }
    private function sumRoomTypeBuckets(array $buckets): int { return $buckets['small'] + $buckets['medium'] + $buckets['large']; }
    private function sumAllRoomBuckets(array $breakdown): int { return array_sum(array_map(fn (array $buckets): int => $this->sumRoomTypeBuckets($buckets), $breakdown)); }
    private function roundToHalfHour(float $hours): float { return ceil($hours * 2.0) / 2.0; }
    private function sizeTier(float $sqm): string { return $sqm < 80.0 ? 'small' : ($sqm < 140.0 ? 'medium' : ($sqm < 220.0 ? 'large' : 'very_large')); }
    private function normalizeCoordinate(mixed $coordinate): ?float { return is_numeric($coordinate) ? round((float) $coordinate, 6) : null; }
    private function normalizePreferredWorkerId(mixed $id): ?int { return is_numeric($id) && (int) $id > 0 ? (int) $id : null; }
    private function normalizeCleaningMode(mixed $mode): string { $mode = mb_strtolower(mb_trim((string) ($mode ?? 'regular'))); return in_array($mode, self::CLEANING_MODES, true) ? $mode : 'regular'; }
    private function suggestedEventTeamSize(int $guestCount): int { return max(1, (int) ceil($guestCount / 10)); }
    private function eventOrderHourlyRate(): float { $rate = (float) (CleaningFinancialSetting::query()->value('extension_rate_per_30_minutes') ?? 0); if ($rate <= 0) { throw new InvalidArgumentException('Event assistance hourly rate is not configured.'); } return $this->pricingCalculator->roundMoney($rate * 2); }
    private function baseSqmByPropertyTypeLegacy(string $type): float { return match ($type) { 'villa' => 120.0, 'house' => 90.0, 'office' => 75.0, default => 65.0 }; }
    private function livingRoomSqmAdjustmentLegacy(string $size): float { return match ($size) { 'small' => 10.0, 'large' => 25.0, 'very_large' => 40.0, default => 15.0 }; }
    private function pricePerSqmByPropertyTypeLegacy(string $type): float { return match ($type) { 'villa' => 9.0, 'house' => 8.0, 'office' => 8.5, default => 8.0 }; }

    private function legacyDetailsToRoomBreakdown(array $details): array
    {
        $breakdown = $this->emptyRoomBreakdown();
        $breakdown['bedroom']['medium'] = max(0, (int) ($details['rooms'] ?? $details['bedrooms'] ?? 0));
        $breakdown['bathroom']['medium'] = max(0, (int) ($details['bathrooms'] ?? 0));
        $breakdown['toilet']['small'] = max(0, (int) ($details['toilets'] ?? 0));
        $breakdown['kitchen']['medium'] = max(0, (int) ($details['kitchens'] ?? 0));
        $breakdown['living_room'][$this->normalizeRoomSize((string) ($details['living_room_size'] ?? 'medium'))] = 1;
        $breakdown['balcony']['small'] = max(0, (int) ($details['balconies'] ?? 0));
        return $breakdown;
    }

    private function emptyRoomBreakdown(): array
    {
        $breakdown = [];
        foreach (self::ROOM_SIZE_BREAKDOWN_TYPES as $type) { foreach (CleaningFinancialDefaults::ROOM_SIZES as $size) { $breakdown[$type][$size] = 0; } }
        return $breakdown;
    }

    private function normalizeRoomSize(string $size): string
    {
        $size = mb_strtolower(mb_trim($size));
        return $size === 'very_large' ? 'large' : (in_array($size, CleaningFinancialDefaults::ROOM_SIZES, true) ? $size : 'medium');
    }

    private function normalizedRoomSizeRanges(mixed $value): array
    {
        $defaults = CleaningFinancialDefaults::roomSizeRanges();
        $saved = is_array($value) ? $value : [];
        foreach (self::ROOM_SIZE_BREAKDOWN_TYPES as $type) { foreach (CleaningFinancialDefaults::ROOM_SIZES as $size) { $min = $saved[$type][$size]['min'] ?? $defaults[$type][$size]['min']; $max = $saved[$type][$size]['max'] ?? $defaults[$type][$size]['max']; $avg = $saved[$type][$size]['average'] ?? null; $defaults[$type][$size]['min'] = is_numeric($min) ? (float) $min : $defaults[$type][$size]['min']; $defaults[$type][$size]['max'] = is_numeric($max) ? (float) $max : $defaults[$type][$size]['max']; $defaults[$type][$size]['average'] = is_numeric($avg) ? (float) $avg : round(($defaults[$type][$size]['min'] + $defaults[$type][$size]['max']) / 2, 2); } }
        return $defaults;
    }

    private function normalizedRoomPricingUnits(mixed $value): array
    {
        $defaults = CleaningFinancialDefaults::roomPricingUnits();
        $saved = is_array($value) ? $value : [];
        foreach (self::ROOM_SIZE_BREAKDOWN_TYPES as $type) { foreach (CleaningFinancialDefaults::ROOM_SIZES as $size) { $savedValue = $saved[$type][$size] ?? null; $defaults[$type][$size] = is_numeric($savedValue) ? max(0.0, (float) $savedValue) : $defaults[$type][$size]; } }
        return $defaults;
    }

    private function normalizedRoomTimeMinutes(mixed $value): array
    {
        $defaults = CleaningFinancialDefaults::roomTimeMinutes();
        $saved = is_array($value) ? $value : [];
        foreach (self::ROOM_SIZE_BREAKDOWN_TYPES as $type) { foreach (CleaningFinancialDefaults::ROOM_SIZES as $size) { foreach (self::CLEANING_MODES as $mode) { $savedValue = $saved[$type][$size][$mode] ?? null; $defaults[$type][$size][$mode] = is_numeric($savedValue) ? max(0, (int) $savedValue) : $defaults[$type][$size][$mode]; } } }
        return $defaults;
    }
}
