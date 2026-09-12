<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use InvalidArgumentException;
use Modules\Cleaning\Models\CleaningDirtinessLevel;
use Modules\Cleaning\Models\CleaningSpecialService;
use Modules\Cleaning\Models\CleaningSpecialServiceDirtinessRule;

final class CleaningSpecialServiceQuoteService
{
    /** @param array<int,array<string,mixed>> $requestedLines */
    public function quote(array $requestedLines): array
    {
        $requestedIds = [];
        foreach ($requestedLines as $line) {
            $serviceId = (int) ($line['serviceId'] ?? $line['specialServiceId'] ?? 0);
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
                'dirtinessLevels' => fn ($query) => $query->where('is_active', true)->orderBy('sort_order'),
                'dirtinessRules' => fn ($query) => $query->where('is_active', true),
            ])
            ->get()
            ->keyBy('id');
        $lines = [];

        foreach ($requestedLines as $requestedLine) {
            $serviceId = (int) ($requestedLine['serviceId'] ?? $requestedLine['specialServiceId'] ?? 0);
            $service = $services->get($serviceId);
            if (! $service instanceof CleaningSpecialService) {
                throw new InvalidArgumentException('The selected special service is not available.');
            }

            $baseUnitPrice = max(0.0, (float) $service->base_unit_price);
            $requestedItems = is_array($requestedLine['items'] ?? null) && $requestedLine['items'] !== []
                ? $requestedLine['items']
                : [[
                    'quantity' => $requestedLine['quantity'] ?? null,
                    'dirtinessLevel' => $requestedLine['dirtinessLevel'] ?? null,
                    'notes' => $requestedLine['notes'] ?? null,
                ]];
            $items = [];

            foreach ($requestedItems as $requestedItem) {
                if (! is_array($requestedItem)) {
                    throw new InvalidArgumentException('Each special service item must be an object.');
                }
                $quantity = round((float) ($requestedItem['quantity'] ?? 0), 3);
                if ($quantity <= 0) {
                    throw new InvalidArgumentException('A special service quantity must be greater than zero.');
                }

                [$levelId, $levelName, $multiplier] = $this->resolveDirtiness($service, $requestedItem);
                $items[] = [
                    'dirtinessLevelId' => $levelId,
                    'dirtinessLevel' => $levelName,
                    'quantity' => $quantity,
                    'baseUnitPrice' => $baseUnitPrice,
                    'priceMultiplier' => $multiplier,
                    'totalPrice' => round($quantity * $baseUnitPrice * $multiplier, 2),
                    'notes' => isset($requestedItem['notes']) ? mb_trim((string) $requestedItem['notes']) : null,
                    'beforeImages' => array_values((array) ($requestedItem['beforeImages'] ?? $requestedItem['attachments'] ?? [])),
                    'afterImages' => array_values((array) ($requestedItem['afterImages'] ?? [])),
                ];
            }

            $quantity = round((float) array_sum(array_column($items, 'quantity')), 3);
            $totalPrice = round((float) array_sum(array_column($items, 'totalPrice')), 2);
            $firstItem = $items[0];
            $lines[] = [
                'specialServiceId' => (int) $service->id,
                'serviceId' => (int) $service->id,
                'name' => $service->name,
                'imageUrl' => $service->imageUrl(),
                'pricingUnit' => $service->pricing_unit,
                'inputType' => $service->input_type,
                'unitCode' => $service->unit_code,
                'dirtinessLevel' => $firstItem['dirtinessLevel'],
                'quantity' => $quantity,
                'baseUnitPrice' => $baseUnitPrice,
                'priceMultiplier' => $firstItem['priceMultiplier'],
                'totalPrice' => $totalPrice,
                'notes' => isset($requestedLine['notes']) ? mb_trim((string) $requestedLine['notes']) : null,
                'sessionIds' => array_values(array_unique(array_filter(
                    array_map('intval', (array) ($requestedLine['sessionIds'] ?? [])),
                    static fn (int $id): bool => $id > 0,
                ))),
                'items' => $items,
                'equipment' => $service->equipment->map(fn ($equipment): array => [
                    'id' => (int) $equipment->id,
                    'name' => $equipment->name,
                    'assetCode' => $equipment->asset_code,
                ])->values()->all(),
                'financialSnapshot' => [
                    'workerPayMode' => $service->worker_pay_mode,
                    'workerPayValue' => (float) $service->worker_pay_value,
                    'operatingCostMode' => $service->operating_cost_mode,
                    'operatingCostValue' => (float) $service->operating_cost_value,
                    'travelFeeMode' => $service->travel_fee_mode,
                    'travelFeeValue' => (float) $service->travel_fee_value,
                ],
            ];
        }

        return ['lines' => $lines, 'total' => round(array_sum(array_column($lines, 'totalPrice')), 2)];
    }

    /** @return array{0:?int,1:?string,2:float} */
    private function resolveDirtiness(CleaningSpecialService $service, array $item): array
    {
        if (! (bool) $service->supports_dirtiness) {
            return [null, null, 1.0];
        }

        $levelId = (int) ($item['dirtinessLevelId'] ?? 0);
        if ($levelId > 0) {
            $level = $service->dirtinessLevels->firstWhere('id', $levelId);
            if (! $level instanceof CleaningDirtinessLevel) {
                throw new InvalidArgumentException('The selected dirtiness level is not available for this special service.');
            }

            return [(int) $level->id, (string) $level->slug, max(0.0, (float) $level->price_multiplier)];
        }

        $legacyName = mb_trim((string) ($item['dirtinessLevel'] ?? ''));
        $legacy = $service->dirtinessRules->firstWhere('dirtiness_level', $legacyName);
        if (! $legacy instanceof CleaningSpecialServiceDirtinessRule) {
            throw new InvalidArgumentException('The selected dirtiness level is not available for this special service.');
        }

        return [null, (string) $legacy->dirtiness_level, max(0.0, (float) $legacy->price_multiplier)];
    }
}
