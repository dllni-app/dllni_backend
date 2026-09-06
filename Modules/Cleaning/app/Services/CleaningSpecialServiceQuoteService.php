<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use InvalidArgumentException;
use Modules\Cleaning\Models\CleaningSpecialService;
use Modules\Cleaning\Models\CleaningSpecialServiceDirtinessRule;

final class CleaningSpecialServiceQuoteService
{
    /**
     * @param  array<int, array<string, mixed>>  $requestedLines
     * @return array{lines:array<int, array<string, mixed>>,total:float}
     */
    public function quote(array $requestedLines): array
    {
        $requestedIds = [];
        foreach ($requestedLines as $line) {
            $serviceId = (int) ($line['specialServiceId'] ?? 0);
            if ($serviceId <= 0) {
                throw new InvalidArgumentException('A special service is required.');
            }
            if (in_array($serviceId, $requestedIds, true)) {
                throw new InvalidArgumentException('A special service can only be requested once per booking.');
            }
            $requestedIds[] = $serviceId;
        }

        $services = CleaningSpecialService::query()
            ->whereIn('id', $requestedIds)
            ->where('is_active', true)
            ->with([
                'equipment' => fn ($query) => $query->where('is_active', true),
                'dirtinessRules' => fn ($query) => $query->where('is_active', true),
            ])
            ->get()
            ->keyBy('id');
        $lines = [];

        foreach ($requestedLines as $requestedLine) {
            $serviceId = (int) $requestedLine['specialServiceId'];
            $service = $services->get($serviceId);
            if (! $service instanceof CleaningSpecialService) {
                throw new InvalidArgumentException('The selected special service is not available.');
            }

            $quantity = (float) ($requestedLine['quantity'] ?? 0);
            if ($quantity <= 0) {
                throw new InvalidArgumentException('A special service quantity must be greater than zero.');
            }

            $dirtinessLevel = (string) ($requestedLine['dirtinessLevel'] ?? '');
            $rule = $service->dirtinessRules
                ->firstWhere('dirtiness_level', $dirtinessLevel);
            if (! $rule instanceof CleaningSpecialServiceDirtinessRule) {
                throw new InvalidArgumentException('The selected dirtiness level is not available for this special service.');
            }

            $baseUnitPrice = max(0.0, (float) $service->base_unit_price);
            $multiplier = max(0.0, (float) $rule->price_multiplier);
            $totalPrice = round($quantity * $baseUnitPrice * $multiplier, 2);
            $lines[] = [
                'specialServiceId' => (int) $service->id,
                'name' => $service->name,
                'imageUrl' => $service->image_url,
                'pricingUnit' => $service->pricing_unit,
                'dirtinessLevel' => $rule->dirtiness_level,
                'quantity' => round($quantity, 3),
                'baseUnitPrice' => $baseUnitPrice,
                'priceMultiplier' => $multiplier,
                'totalPrice' => $totalPrice,
                'notes' => isset($requestedLine['notes']) ? mb_trim((string) $requestedLine['notes']) : null,
                'equipment' => $service->equipment
                    ->map(fn ($equipment): array => ['id' => (int) $equipment->id, 'name' => $equipment->name])
                    ->values()
                    ->all(),
            ];
        }

        return [
            'lines' => $lines,
            'total' => round(array_sum(array_column($lines, 'totalPrice')), 2),
        ];
    }
}
