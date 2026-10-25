<?php

declare(strict_types=1);

namespace Modules\Cleaning\Support;

/**
 * Offer-safe room requirements. This contains quantities and cleaning modes,
 * not room identities, assignments or a customer's exact location.
 */
final class WorkerBookingRequirementsPresenter
{
    /**
     * @param array<int, array<string, mixed>> $rooms
     * @return array<int, array<string, mixed>>
     */
    public static function summarize(array $rooms, ?array $propertyDetails = null): array
    {
        // Legacy/test bookings may predate persisted cleaning_booking_rooms.
        // Recover only counts explicitly recorded by the customer, without
        // inventing room identities, sizes, or per-room cleaning modes.
        if ($rooms === [] && is_array($propertyDetails)) {
            $breakdown = $propertyDetails['room_size_breakdown'] ?? null;
            if (is_array($breakdown) && $breakdown !== []) {
                $labels = [
                    'bedroom' => 'غرفة نوم',
                    'bathroom' => 'حمام',
                    'kitchen' => 'مطبخ',
                    'living_room' => 'غرفة معيشة',
                    'balcony' => 'شرفة',
                    'shed' => 'مستودع',
                    'corridor' => 'ممر',
                ];
                foreach ($breakdown as $type => $sizes) {
                    if (! is_array($sizes)) {
                        continue;
                    }
                    foreach ($sizes as $size => $count) {
                        for ($i = 0; $i < min(40, max(0, (int) $count)); $i++) {
                            $rooms[] = [
                                'roomType' => (string) $type,
                                'roomTypeLabel' => $labels[$type] ?? (string) $type,
                                'roomSize' => (string) $size,
                                'cleaningMode' => $propertyDetails['cleaning_mode'] ?? 'regular',
                            ];
                        }
                    }
                }
            }
            if ($rooms !== []) {
                // Avoid double counting: modern breakdown already includes
                // the rooms represented in the legacy aggregate counts.
                return self::summarize($rooms);
            }
            $legacy = [
                'bedroom' => ['bedrooms', 'غرفة نوم'],
                'bathroom' => ['bathrooms', 'حمام'],
                'kitchen' => ['kitchens', 'مطبخ'],
                'balcony' => ['balconies', 'شرفة'],
                'shed' => ['sheds', 'مستودع'],
            ];
            foreach ($legacy as $type => [$key, $label]) {
                $count = max(0, (int) ($propertyDetails[$key] ?? 0));
                for ($i = 0; $i < min($count, 40); $i++) {
                    $rooms[] = [
                        'roomType' => $type,
                        'roomTypeLabel' => $label,
                        'cleaningMode' => $propertyDetails['cleaning_mode'] ?? 'regular',
                    ];
                }
            }
            if (! empty($propertyDetails['living_room_size'])) {
                $rooms[] = [
                    'roomType' => 'living_room',
                    'roomTypeLabel' => 'غرفة معيشة',
                    'roomSize' => $propertyDetails['living_room_size'],
                    'cleaningMode' => $propertyDetails['cleaning_mode'] ?? 'regular',
                ];
            }
        }

        $grouped = [];
        foreach ($rooms as $room) {
            if (! is_array($room)) {
                continue;
            }

            $type = (string) ($room['roomType'] ?? 'other');
            $size = (string) ($room['roomSize'] ?? '');
            $mode = (string) ($room['cleaningMode'] ?? 'regular');
            $key = $type.'|'.$size.'|'.$mode;

            if (! isset($grouped[$key])) {
                $grouped[$key] = [
                    'roomType' => $type,
                    'roomTypeLabel' => $room['roomTypeLabel'] ?? $type,
                    'roomSize' => $size !== '' ? $size : null,
                    'roomSizeLabel' => $room['roomSizeLabel'] ?? null,
                    'cleaningMode' => $mode,
                    'cleaningModeLabel' => $room['cleaningModeLabel'] ?? ($mode === 'deep' ? 'تعزيل' : 'حوسة'),
                    'count' => 0,
                ];
            }
            $grouped[$key]['count']++;
        }

        return array_values($grouped);
    }
}
