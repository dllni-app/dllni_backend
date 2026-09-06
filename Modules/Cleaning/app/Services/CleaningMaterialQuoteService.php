<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use Modules\Cleaning\Models\CleaningMaterial;
use Modules\Cleaning\Models\CleaningMaterialQuantityRule;

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

        $materials = CleaningMaterial::query()
            ->where('is_active', true)
            ->whereHas('materialType', fn ($query) => $query->where('is_active', true))
            ->with([
                'materialType.unit' => fn ($query) => $query->where('is_active', true),
                'quantityRules' => fn ($query) => $query->where('is_active', true),
            ])
            ->get();

        foreach ($materials as $material) {
            if ($material->materialType?->unit === null) {
                continue;
            }

            $quantity = $this->quantityForMaterial($material, $roomBreakdown, $cleaningMode);
            if ($quantity <= 0) {
                continue;
            }

            $unitPrice = max(0.0, (float) $material->materialType->price_per_unit);
            $lines[] = [
                'materialId' => (int) $material->id,
                'materialTypeId' => (int) $material->materialType->id,
                'unitId' => (int) $material->materialType->unit->id,
                'name' => $material->name,
                'quantity' => $quantity,
                'unit' => $material->materialType->unit->code,
                'unitLabel' => $material->materialType->unit->name,
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
    private function quantityForMaterial(CleaningMaterial $material, array $roomBreakdown, string $cleaningMode): float
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

                $rule = $this->bestRule($material, (string) $roomType, (string) $roomSize, $cleaningMode);
                if (! $rule instanceof CleaningMaterialQuantityRule) {
                    continue;
                }

                $quantity += $roomCount * max(0.0, (float) $rule->quantity_per_room);
            }
        }

        return round($quantity, 3);
    }

    private function bestRule(
        CleaningMaterial $material,
        string $roomType,
        string $roomSize,
        string $cleaningMode,
    ): ?CleaningMaterialQuantityRule {
        return $material->quantityRules
            ->filter(function (CleaningMaterialQuantityRule $rule) use ($roomType, $roomSize, $cleaningMode): bool {
                return ($rule->room_type === null || $rule->room_type === $roomType)
                    && ($rule->room_size === null || $rule->room_size === $roomSize)
                    && ($rule->cleaning_mode === null || $rule->cleaning_mode === $cleaningMode);
            })
            ->sortByDesc(static fn (CleaningMaterialQuantityRule $rule): int =>
                (int) ($rule->room_type !== null)
                + (int) ($rule->room_size !== null)
                + (int) ($rule->cleaning_mode !== null)
            )
            ->first();
    }
}
