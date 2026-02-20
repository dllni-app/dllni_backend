<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\ServiceAddon;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ServiceAddon
 */
final class ServiceAddonResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'pricingType' => $this->pricing_type?->value ?? $this->pricing_type,
            'priceValue' => (float) $this->price_value,
            'isActive' => $this->is_active,
            'createdAt' => $this->created_at->toDateTimeString(),
            'updatedAt' => $this->updated_at->toDateTimeString(),
        ];
    }
}
