<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use InvalidArgumentException;
use Modules\Cleaning\Models\CleaningMaterial;
use Modules\Cleaning\Models\CleaningMaterialType;
use Modules\Cleaning\Models\CleaningMaterialTypeQuantityRule;

final class CleaningMaterialQuoteService
{
    /**
     * @param  array<string, mixed>  $propertyDetails
     * @return array{lines:array<int, array<string, mixed>>,total:float}
     */
    public function quote(array $propertyDetails): array
    {
        $roomBreakdown = is_array($propertyDetails['room_size_breakdown'] ?? null)
            ? $propertyDetails['room_size_breakdown']
            : [];
        $cleaningMode = (string) ($propertyDetails['cleaning_mode'] ?? 'regular');
        $lines = [];

        $materialTypes = CleaningMaterialType::query()
            ->where('is_active', true)
            ->whereHas('unit', fn ($query) => $query->where('is_active', true))
            ->with([
                'unit',
                'materials' => fn ($query) => $query
                    ->where('is_active', true)
                    ->with(['quantityRules' => fn ($rules) => $rules->where('is_active', true)])
                    ->orderBy('id'),
                'quantityRules' => fn ($query) => $query
                    ->where('is_active', true)
                    ->where('requires_admin_resolution', false),
            ])
            ->get();

        foreach ($materialTypes as $materialType) {
            if ($materialType->unit === null) {
                continue;
            }

            $quantity = $this->quantityForType($materialType, $roomBreakdown, $cleaningMode);
            if ($quantity <= 0) {
                continue;
            }

            $available = round((float) $materialType->materials->sum('stock_quantity'), 3);
            if ($available < $quantity) {
                throw new InvalidArgumentException("Insufficient stock for {$materialType->name}.");
            }

            $unitPrice = max(0.0, (float) $materialType->price_per_unit);
            $lines[] = [
                // Kept as a read-only legacy hint. Reservation always allocates
                // across products from the material type under a database lock.
                'materialId' => (int) ($materialType->materials->first()?->id ?? 0),
                'materialTypeId' => (int) $materialType->id,
                'unitId' => (int) $materialType->unit->id,
                'name' => $materialType->name,
                'quantity' => $quantity,
                'unit' => $materialType->unit->code,
                'unitLabel' => $materialType->unit->name,
                'unitPrice' => $unitPrice,
                'totalPrice' => round($quantity * $unitPrice, 2),
            ];
        }

        return [
            'lines' => $lines,
            'total' => round(array_sum(array_column($lines, 'totalPrice')), 2),
        ];
    }

    /**
     * @param  array<string, mixed>  $roomBreakdown
     */
    private function quantityForType(CleaningMaterialType $materialType, array $roomBreakdown, string $cleaningMode): float
    {
        $quantity = 0.0;

        foreach ($roomBreakdown as $roomType => $sizeCounts) {
            if (! is_array($sizeCounts)) {
                continue;
            }

            foreach ($sizeCounts as $roomSize => $count) {
                $roomCount = max(0, (int) $count);
                if ($roomCount === 0) {
                    continue;
                }

                $rule = $this->bestRule($materialType, (string) $roomType, (string) $roomSize, $cleaningMode);
                if (! $rule instanceof CleaningMaterialTypeQuantityRule) {
                    continue;
                }

                $quantity += $roomCount * max(0.0, (float) $rule->quantity_per_room);
            }
        }

        return round($quantity, 3);
    }

    private function bestRule(
        CleaningMaterialType $materialType,
        string $roomType,
        string $roomSize,
        string $cleaningMode,
    ): ?CleaningMaterialTypeQuantityRule {
        $typeRule = $materialType->quantityRules
            ->filter(function (CleaningMaterialTypeQuantityRule $rule) use ($roomType, $roomSize, $cleaningMode): bool {
                return ($rule->room_type === null || $rule->room_type === $roomType)
                    && ($rule->room_size === null || $rule->room_size === $roomSize)
                    && ($rule->cleaning_mode === null || $rule->cleaning_mode === $cleaningMode);
            })
            ->sortByDesc(static fn (CleaningMaterialTypeQuantityRule $rule): int =>
                (int) ($rule->room_type !== null)
                + (int) ($rule->room_size !== null)
                + (int) ($rule->cleaning_mode !== null)
            )
            ->first();

        if ($typeRule instanceof CleaningMaterialTypeQuantityRule) {
            return $typeRule;
        }

        // Compatibility for v2 records written against the original product-
        // scoped rules. New rules are type-scoped, but an unambiguous legacy
        // match remains readable until administrators complete the backfill.
        $legacyRule = $materialType->materials
            ->flatMap(static fn (CleaningMaterial $material) => $material->quantityRules)
            ->filter(function ($rule) use ($roomType, $roomSize, $cleaningMode): bool {
                return ($rule->room_type === null || $rule->room_type === $roomType)
                    && ($rule->room_size === null || $rule->room_size === $roomSize)
                    && ($rule->cleaning_mode === null || $rule->cleaning_mode === $cleaningMode);
            })
            ->sortByDesc(static fn ($rule): int =>
                (int) ($rule->room_type !== null)
                + (int) ($rule->room_size !== null)
                + (int) ($rule->cleaning_mode !== null)
            )
            ->first();

        if ($legacyRule === null) {
            return null;
        }

        return new CleaningMaterialTypeQuantityRule([
            'room_type' => $legacyRule->room_type,
            'room_size' => $legacyRule->room_size,
            'cleaning_mode' => $legacyRule->cleaning_mode,
            'quantity_per_room' => $legacyRule->quantity_per_room,
            'is_active' => true,
            'requires_admin_resolution' => false,
        ]);
    }
}
