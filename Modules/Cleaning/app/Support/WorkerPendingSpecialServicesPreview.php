<?php

declare(strict_types=1);

namespace Modules\Cleaning\Support;

use Modules\Cleaning\Models\CleaningBooking;

final class WorkerPendingSpecialServicesPreview
{
    /** @return list<array<string, mixed>> */
    public static function forBooking(CleaningBooking $booking): array
    {
        return $booking->specialServices()->get()->map(static function ($line): array {
            $equipment = collect(is_array($line->equipment_snapshot) ? $line->equipment_snapshot : [])
                ->filter(static fn ($item): bool => is_array($item) && is_string($item['name'] ?? null))
                ->pluck('name')->values()->all();

            return [
                'name' => (string) $line->service_name,
                'quantity' => (float) $line->quantity,
                'pricingUnit' => (string) $line->pricing_unit,
                'dirtinessLevel' => (string) $line->dirtiness_level,
                'equipmentNames' => $equipment,
            ];
        })->values()->all();
    }
}
