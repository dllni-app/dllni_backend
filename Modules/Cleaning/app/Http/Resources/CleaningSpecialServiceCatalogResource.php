<?php

declare(strict_types=1);

namespace Modules\Cleaning\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Cleaning\Models\CleaningSpecialService;

/** @mixin CleaningSpecialService */
final class CleaningSpecialServiceCatalogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'category' => 'special_service',
            'isActive' => (bool) $this->is_active,
            'image' => $this->image_url,
            'pricingUnit' => $this->pricing_unit,
            'baseUnitPrice' => (float) $this->base_unit_price,
            'dirtinessRules' => $this->whenLoaded('dirtinessRules', fn () => $this->dirtinessRules->map(static fn ($rule): array => [
                'level' => $rule->dirtiness_level,
                'priceMultiplier' => (float) $rule->price_multiplier,
                'isActive' => (bool) $rule->is_active,
            ])->values()),
            'equipment' => $this->whenLoaded('equipment', fn () => $this->equipment->map(static fn ($equipment): array => [
                'id' => $equipment->id,
                'name' => $equipment->name,
            ])->values()),
            'pricing' => [],
        ];
    }
}
