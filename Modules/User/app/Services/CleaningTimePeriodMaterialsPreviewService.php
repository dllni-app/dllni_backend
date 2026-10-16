<?php

declare(strict_types=1);

namespace Modules\User\Services;

use Modules\Cleaning\Models\CleaningMaterialType;
use Modules\Cleaning\Services\CleaningMaterialQuoteService;

final class CleaningTimePeriodMaterialsPreviewService
{
    public function __construct(
        private readonly CleaningMaterialQuoteService $materials,
        private readonly UserCleaningOrderEstimationService $estimator,
    ) {}

    public function preview(array $rooms): array
    {
        $needs = [];
        foreach ($rooms as $room) {
            $property = [
                'cleaning_mode' => $room['cleaningMode'],
                'room_size_breakdown' => [$room['roomType'] => [$room['size'] => 1]],
            ];
            foreach ($this->materials->quote($property, null, false, false)['lines'] as $line) {
                $id = (int) $line['materialTypeId'];
                if (! isset($needs[$id])) {
                    $needs[$id] = [
                        'materialTypeId' => $id,
                        'name' => (string) $line['name'],
                        'quantity' => 0.0,
                        'unit' => (string) $line['unit'],
                        'unitLabel' => (string) $line['unitLabel'],
                        'requiredForRoomKeys' => [],
                        'isRequired' => true,
                    ];
                }
                // A shared material package is required once at the greatest
                // per-room quantity, rather than once for every room.
                $needs[$id]['quantity'] = max($needs[$id]['quantity'], (float) $line['quantity']);
                $needs[$id]['requiredForRoomKeys'][] = $room['roomKey'];
            }
        }

        ksort($needs, SORT_NUMERIC);
        foreach ($needs as &$item) {
            if (in_array(mb_strtolower($item['unit']), ['pack', 'package', 'bottle', 'piece'], true)) {
                $item['quantity'] = (float) ceil($item['quantity']);
            }
            $item['quantity'] = round($item['quantity'], 3);
            $item['requiredForRoomKeys'] = array_values(array_unique($item['requiredForRoomKeys']));
            sort($item['requiredForRoomKeys'], SORT_STRING);
        }
        unset($item);

        $items = array_values($needs);
        // Include server-derived room work estimates in the existing, early
        // materials preview. No pricing quote is created or exposed here.
        $roomWorkMinutes = [];
        foreach ($rooms as $room) {
            $details = [
                'cleaning_mode' => $room['cleaningMode'],
                'room_size_breakdown' => [$room['roomType'] => [$room['size'] => 1]],
            ];
            $estimate = $this->estimator->price(
                'apartment', $details, null, null, null, null, false, [], 1
            );
            $line = $estimate['roomPricingLines'][0] ?? null;
            if (is_array($line) && (int) ($line['totalMinutes'] ?? 0) > 0) {
                $roomWorkMinutes[$room['roomKey']] = (int) $line['totalMinutes'];
            }
        }
        $catalogAvailable = CleaningMaterialType::query()->where('is_active', true)
            ->whereHas('unit', static fn ($query) => $query->where('is_active', true))
            ->exists();

        return [
            'items' => $items,
            'roomWorkMinutes' => $roomWorkMinutes,
            'catalogStatus' => $catalogAvailable ? 'ready' : 'empty',
            'sourceRevision' => hash('sha256', json_encode($items, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)),
        ];
    }
}
