<?php

declare(strict_types=1);

namespace Modules\Cleaning\Support;

use Illuminate\Support\Arr;

final class WorkerRoomAssignmentPlanner
{
    public const ROOM_TYPE_ORDER = ['bedroom', 'bathroom', 'kitchen', 'living_room', 'balcony', 'corridor', 'shed'];

    public const ROOM_SIZE_ORDER = ['small', 'medium', 'large'];

    /**
     * @param  array<string, mixed>  $propertyDetails
     * @param  array<int, array<string, mixed>>|null  $workerRoomAssignments
     * @return array{
     *     errors: array<string, array<int, string>>,
     *     assignments: array<int, array{
     *         workerSlot:int,
     *         preferredWorkerId:?int,
     *         rooms: array<int, array{roomKey:string, roomType:string, roomSize:string, weight:float}>,
     *         roomsWeight: float
     *     }>,
     *     roomPlans: array<string, array{workerSlot:int, preferredWorkerId:?int}>,
     *     derivedRooms: array<int, array{room_key:string, room_type:string, room_size:string, display_label:string, weight:float}>
     * }
     */
    public static function plan(
        array $propertyDetails,
        ?array $workerRoomAssignments,
        string $assignmentMode,
        int $numberOfWorkers,
        ?int $preferredWorkerId,
    ): array {
        $derivedRooms = self::generateRoomBlueprints($propertyDetails);

        if ($workerRoomAssignments === null) {
            return [
                'errors' => [],
                'assignments' => [],
                'roomPlans' => [],
                'derivedRooms' => $derivedRooms,
            ];
        }

        $workerRoomAssignments = self::normalizePreferredWorkerAssignments(
            $workerRoomAssignments,
            $assignmentMode,
            $preferredWorkerId,
        );

        $errors = [];
        $roomMap = [];
        foreach ($derivedRooms as $room) {
            $roomMap[$room['room_key']] = $room;
        }

        $normalizedAssignments = [];
        $seenSlots = [];
        $seenRoomKeys = [];

        foreach ($workerRoomAssignments as $assignmentIndex => $assignment) {
            $slotPath = "workerRoomAssignments.{$assignmentIndex}.workerSlot";
            $preferredWorkerPath = "workerRoomAssignments.{$assignmentIndex}.preferredWorkerId";
            $roomsPath = "workerRoomAssignments.{$assignmentIndex}.rooms";

            $workerSlot = is_numeric($assignment['workerSlot'] ?? null)
                ? (int) $assignment['workerSlot']
                : null;

            if ($workerSlot === null || $workerSlot < 1) {
                $errors[$slotPath][] = 'The worker slot must be a positive integer.';
                continue;
            }

            if ($workerSlot > max(1, $numberOfWorkers)) {
                $errors[$slotPath][] = 'The worker slot must not exceed numberOfWorkers.';
            }

            if (in_array($workerSlot, $seenSlots, true)) {
                $errors[$slotPath][] = 'Each worker slot can only appear once.';
            } else {
                $seenSlots[] = $workerSlot;
            }

            $slotPreferredWorkerId = self::normalizeNullableInt($assignment['preferredWorkerId'] ?? null);

            if ($assignmentMode === 'open_count' && $slotPreferredWorkerId !== null) {
                $errors[$preferredWorkerPath][] = 'Open count room assignments cannot target a preferred worker.';
            }
            
            $rooms = $assignment['rooms'] ?? null;
            if (! is_array($rooms)) {
                $errors[$roomsPath][] = 'The rooms field must be an array.';
                continue;
            }

            $normalizedRooms = [];

            foreach ($rooms as $roomIndex => $room) {
                $roomKeyPath = "{$roomsPath}.{$roomIndex}.roomKey";
                $roomTypePath = "{$roomsPath}.{$roomIndex}.roomType";
                $roomSizePath = "{$roomsPath}.{$roomIndex}.roomSize";

                $roomKey = is_string($room['roomKey'] ?? null) ? trim((string) $room['roomKey']) : '';
                if ($roomKey === '') {
                    $errors[$roomKeyPath][] = 'The room key is required.';
                    continue;
                }

                $derivedRoom = $roomMap[$roomKey] ?? null;
                if ($derivedRoom === null) {
                    $errors[$roomKeyPath][] = 'The selected room does not exist in the derived room breakdown.';
                    continue;
                }

                if (isset($seenRoomKeys[$roomKey])) {
                    $errors[$roomKeyPath][] = 'Each room can only be assigned once.';
                    continue;
                }

                $providedRoomType = is_string($room['roomType'] ?? null) ? trim((string) $room['roomType']) : '';
                if ($providedRoomType === '' || $providedRoomType !== $derivedRoom['room_type']) {
                    $errors[$roomTypePath][] = 'The room type must match the derived room.';
                }

                $providedRoomSize = is_string($room['roomSize'] ?? null) ? trim((string) $room['roomSize']) : '';
                if ($providedRoomSize === '' || $providedRoomSize !== $derivedRoom['room_size']) {
                    $errors[$roomSizePath][] = 'The room size must match the derived room.';
                }

                $seenRoomKeys[$roomKey] = true;
                $normalizedRooms[] = [
                    'roomKey' => $derivedRoom['room_key'],
                    'roomType' => $derivedRoom['room_type'],
                    'roomSize' => $derivedRoom['room_size'],
                    'weight' => (float) $derivedRoom['weight'],
                ];
            }

            $normalizedAssignments[$workerSlot] = [
                'workerSlot' => $workerSlot,
                'preferredWorkerId' => $slotPreferredWorkerId,
                'rooms' => $normalizedRooms,
            ];
        }

        if ($assignmentMode === 'preferred_worker' && count($workerRoomAssignments) !== 1) {
            $errors['workerRoomAssignments'][] = 'Preferred worker mode expects exactly one worker room assignment.';
        }

        if ($errors !== []) {
            return [
                'errors' => $errors,
                'assignments' => [],
                'roomPlans' => [],
                'derivedRooms' => $derivedRooms,
            ];
        }

        for ($slot = 1; $slot <= max(1, $numberOfWorkers); $slot++) {
            $normalizedAssignments[$slot] ??= [
                'workerSlot' => $slot,
                'preferredWorkerId' => $assignmentMode === 'preferred_worker' ? $preferredWorkerId : null,
                'rooms' => [],
            ];
        }

        $unassignedRooms = [];
        foreach ($derivedRooms as $room) {
            if (! isset($seenRoomKeys[$room['room_key']])) {
                $unassignedRooms[] = [
                    'roomKey' => $room['room_key'],
                    'roomType' => $room['room_type'],
                    'roomSize' => $room['room_size'],
                    'weight' => (float) $room['weight'],
                ];
            }
        }

        usort($unassignedRooms, static fn (array $left, array $right): int => $right['weight'] <=> $left['weight']);

        foreach ($unassignedRooms as $room) {
            $targetSlot = self::pickTargetSlot($normalizedAssignments, $assignmentMode);
            $normalizedAssignments[$targetSlot]['rooms'][] = $room;
        }

        ksort($normalizedAssignments);

        $roomPlans = [];
        $orderedAssignments = [];
        foreach ($normalizedAssignments as $slot => $assignment) {
            $assignment['rooms'] = self::sortRooms($assignment['rooms']);
            $assignment['roomsWeight'] = round((float) array_sum(array_map(
                static fn (array $room): float => (float) $room['weight'],
                $assignment['rooms']
            )), 2);

            foreach ($assignment['rooms'] as $room) {
                $roomPlans[$room['roomKey']] = [
                    'workerSlot' => (int) $slot,
                    'preferredWorkerId' => $assignment['preferredWorkerId'],
                ];
            }

            $orderedAssignments[] = $assignment;
        }

        return [
            'errors' => [],
            'assignments' => $orderedAssignments,
            'roomPlans' => $roomPlans,
            'derivedRooms' => $derivedRooms,
        ];
    }

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
     * @param  array<int, array{workerSlot:int, preferredWorkerId:?int, rooms:array<int, array{roomKey:string, roomType:string, roomSize:string, weight:float}>, roomsWeight?:float}>  $assignments
     * @return array<int, array{workerSlot:int, preferredWorkerId:?int, rooms:array<int, array{roomKey:string, roomType:string}>, roomsWeight:float, estimatedServiceShareAmount:float}>
     */
    public static function withPricingPreview(array $assignments, float $subtotal): array
    {
        $totalWeight = round((float) array_sum(array_map(
            static fn (array $assignment): float => (float) ($assignment['roomsWeight'] ?? 0),
            $assignments
        )), 2);

        return array_map(static function (array $assignment) use ($subtotal, $totalWeight): array {
            $roomsWeight = round((float) ($assignment['roomsWeight'] ?? 0), 2);
            $estimatedServiceShareAmount = $totalWeight > 0
                ? round($subtotal * ($roomsWeight / $totalWeight), 2)
                : 0.0;

            return [
                'workerSlot' => $assignment['workerSlot'],
                'preferredWorkerId' => $assignment['preferredWorkerId'],
                'roomsWeight' => $roomsWeight,
                'estimatedServiceShareAmount' => $estimatedServiceShareAmount,
                'rooms' => array_map(static fn (array $room): array => [
                    'roomKey' => $room['roomKey'],
                    'roomType' => $room['roomType'],
                    'roomSize' => $room['roomSize'],
                ], $assignment['rooms']),
            ];
        }, $assignments);
    }

    /**
     * Flutter may send preferred-worker room assignments as one entry per selected room,
     * using workerSlot as a room index and preferredWorkerId as null. For backend domain
     * rules, preferred-worker mode is still one worker, so normalize those entries into
     * the single supported slot before validation and room planning.
     *
     * @param  array<int, array<string, mixed>>  $workerRoomAssignments
     * @return array<int, array<string, mixed>>
     */
    private static function normalizePreferredWorkerAssignments(
        array $workerRoomAssignments,
        string $assignmentMode,
        ?int $preferredWorkerId,
    ): array {
        if ($assignmentMode !== 'preferred_worker') {
            return $workerRoomAssignments;
        }

        $rooms = [];

        foreach ($workerRoomAssignments as $assignment) {
            $assignmentRooms = $assignment['rooms'] ?? null;
            if (! is_array($assignmentRooms)) {
                return $workerRoomAssignments;
            }

            foreach ($assignmentRooms as $room) {
                $rooms[] = $room;
            }
        }

        return [[
            'workerSlot' => 1,
            'preferredWorkerId' => $preferredWorkerId,
            'rooms' => $rooms,
        ]];
    }

    private static function normalizeNullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * @param  array<int, array{workerSlot:int, preferredWorkerId:?int, rooms:array<int, array{roomKey:string, roomType:string, roomSize:string, weight:float}>}>  $assignments
     */
    private static function pickTargetSlot(array $assignments, string $assignmentMode): int
    {
        if ($assignmentMode === 'preferred_worker') {
            return 1;
        }

        $weights = [];
        foreach ($assignments as $slot => $assignment) {
            $weights[$slot] = round((float) array_sum(array_map(
                static fn (array $room): float => (float) $room['weight'],
                $assignment['rooms']
            )), 2);
        }

        asort($weights, SORT_NUMERIC);

        return (int) array_key_first($weights);
    }

    /**
     * @param  array<int, array{roomKey:string, roomType:string, roomSize:string, weight:float}>  $rooms
     * @return array<int, array{roomKey:string, roomType:string, roomSize:string, weight:float}>
     */
    private static function sortRooms(array $rooms): array
    {
        usort($rooms, static function (array $left, array $right): int {
            return strcmp($left['roomKey'], $right['roomKey']);
        });

        return $rooms;
    }

    /**
     * @param  array<string, mixed>  $breakdown
     * @return array<int, array{room_key:string, room_type:string, room_size:string, display_label:string, weight:float}>
     */
    private static function generateBlueprintsFromBreakdown(array $breakdown): array
    {
        $rooms = [];

        foreach (self::ROOM_TYPE_ORDER as $roomType) {
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
