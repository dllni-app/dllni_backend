<?php

declare(strict_types=1);

namespace Modules\Cleaning\Support;

use Illuminate\Support\Arr;

/** Generates room descriptions for pricing only. Never assigns rooms to workers. */
final class CleaningRoomBlueprints
{
    public const ROOM_TYPE_ORDER = ['bedroom', 'bathroom', 'kitchen', 'living_room', 'balcony', 'corridor', 'shed'];
    public const ROOM_SIZE_ORDER = ['small', 'medium', 'large'];

    /**
     * @param  array<string, mixed>  $propertyDetails
     * @return array<int, array{room_key:string, room_type:string, room_size:string, display_label:string, weight:float}>
     */
    public static function generateRoomBlueprints(array $propertyDetails): array
    {
        $breakdown = Arr::get($propertyDetails, 'room_size_breakdown');

        if (is_array($breakdown)) {
            return self::generateBlueprintsFromBreakdown($breakdown);
        }

        return self::generateBlueprintsFromLegacyFields($propertyDetails);
    }

    /**
     * @param  array<string, mixed>  $breakdown
     * @return array<int, array{room_key:string, room_type:string, room_size:string, display_label:string, weight:float}>
     */
    private static function generateBlueprintsFromBreakdown(array $breakdown): array
    {
        $rooms = [];

        foreach (array_values(array_unique([...self::ROOM_TYPE_ORDER, ...array_keys($breakdown)])) as $roomType) {
            $buckets = Arr::get($breakdown, $roomType);
            if (! is_array($buckets)) {
                continue;
            }

            foreach (self::ROOM_SIZE_ORDER as $size) {
                $count = max(0, (int) Arr::get($buckets, $size, 0));
                for ($index = 1; $index <= $count; $index++) {
                    $rooms[] = [
                        'room_key' => sprintf('%s.%s.%d', $roomType, $size, $index),
                        'room_type' => $roomType,
                        'room_size' => $size,
                        'display_label' => self::displayLabel($roomType, $size, $index),
                        'weight' => self::roomWeight($roomType, $size),
                    ];
                }
            }
        }

        return $rooms;
    }

    /**
     * @param  array<string, mixed>  $propertyDetails
     * @return array<int, array{room_key:string, room_type:string, room_size:string, display_label:string, weight:float}>
     */
    private static function generateBlueprintsFromLegacyFields(array $propertyDetails): array
    {
        $rooms = [];

        $legacyGroups = [
            'bedroom' => ['count' => max(0, (int) Arr::get($propertyDetails, 'bedrooms', 0)), 'size' => 'medium'],
            'bathroom' => ['count' => max(0, (int) Arr::get($propertyDetails, 'bathrooms', 0)), 'size' => 'medium'],
            'kitchen' => ['count' => max(0, (int) Arr::get($propertyDetails, 'kitchens', Arr::get($propertyDetails, 'kitchen_included') ? 1 : 0)), 'size' => 'medium'],
            'living_room' => ['count' => 1, 'size' => mb_strtolower((string) Arr::get($propertyDetails, 'living_room_size', 'medium'))],
            'balcony' => ['count' => max(0, (int) Arr::get($propertyDetails, 'balconies', 0)), 'size' => 'small'],
            'corridor' => ['count' => max(0, (int) Arr::get($propertyDetails, 'corridors', 0)), 'size' => 'medium'],
            'shed' => ['count' => max(0, (int) Arr::get($propertyDetails, 'sheds', 0)), 'size' => 'medium'],
            'room' => ['count' => max(0, (int) Arr::get($propertyDetails, 'rooms', 0)), 'size' => 'medium'],
        ];

        foreach ($legacyGroups as $roomType => $definition) {
            $count = (int) $definition['count'];
            $size = (string) $definition['size'];

            if (! in_array($size, self::ROOM_SIZE_ORDER, true)) {
                $size = 'medium';
            }

            for ($index = 1; $index <= $count; $index++) {
                $rooms[] = [
                    'room_key' => sprintf('%s.%s.%d', $roomType, $size, $index),
                    'room_type' => $roomType,
                    'room_size' => $size,
                    'display_label' => self::displayLabel($roomType, $size, $index),
                    'weight' => self::roomWeight($roomType, $size),
                ];
            }
        }

        return $rooms;
    }

    private static function displayLabel(string $roomType, string $size, int $index): string
    {
        $label = match ($roomType) {
            'bedroom' => 'Bedroom',
            'bathroom' => 'Bathroom',
            'kitchen' => 'Kitchen',
            'living_room' => 'Living Room',
            'balcony' => 'Balcony',
            'corridor' => 'Corridor',
            'shed' => 'Shed',
            default => 'Room',
        };

        return sprintf('%s %d - %s', $label, $index, ucfirst($size));
    }

    private static function roomWeight(string $roomType, string $size): float
    {
        $typeWeight = match ($roomType) {
            'bedroom' => 1.0,
            'bathroom' => 0.8,
            'kitchen' => 1.1,
            'living_room' => 1.2,
            'balcony' => 0.5,
            'corridor' => 0.6,
            'shed' => 1.0,
            default => 1.0,
        };

        $sizeWeight = match ($size) {
            'small' => 1.0,
            'medium' => 1.5,
            'large' => 2.0,
            default => 1.0,
        };

        return round($typeWeight * $sizeWeight, 2);
    }
}
