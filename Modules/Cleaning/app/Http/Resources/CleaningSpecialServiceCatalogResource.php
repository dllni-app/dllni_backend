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
            'categoryId' => $this->cleaning_special_service_category_id !== null
                ? (int) $this->cleaning_special_service_category_id
                : null,
            'categoryName' => $this->category?->name,
            'description' => $this->description,
            'isActive' => (bool) $this->is_active,
            'image' => $this->imageUrl(),
            'pricingUnit' => $this->pricing_unit,
            'inputType' => $this->input_type,
            'unitCode' => $this->unit_code,
            'baseUnitPrice' => (float) $this->base_unit_price,
            'supportsDirtiness' => (bool) $this->supports_dirtiness,
            'genderConstraint' => $this->gender_constraint,
            'estimatedDurationMinutes' => (int) $this->estimated_duration_minutes,
            'requiresBeforeImage' => (bool) $this->requires_before_image,
            'requiresAfterImage' => (bool) $this->requires_after_image,
            'dirtinessLevels' => $this->whenLoaded('dirtinessLevels', fn () => $this->dirtinessLevels->map(static fn ($level): array => [
                'id' => (int) $level->id,
                'name' => (string) $level->name,
                'slug' => (string) $level->slug,
                'level' => (string) $level->slug,
                'priceMultiplier' => (float) $level->price_multiplier,
                'isActive' => (bool) $level->is_active,
            ])->values()),
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
