<?php

declare(strict_types=1);

namespace Modules\Resturants\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Resturants\Models\Offer;

/**
 * @mixin Offer
 */
final class OfferResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $imageUrl = null;
        if ($this->relationLoaded('restaurant') && $this->restaurant !== null) {
            $imageUrl = $this->restaurant->getFirstMediaUrl('primary-image') ?: null;
        }

        return [
            ...$this->resource->promotionAttributes(),
            'id' => $this->id,
            'restaurantId' => $this->restaurant_id,
            'name' => $this->name,
            'discountType' => $this->discount_type?->value ?? $this->discount_type,
            'discountValue' => $this->discount_value === null ? null : (float) $this->discount_value,
            'imageUrl' => $imageUrl,
            'startsAt' => $this->resource->isProductDiscount() ? $this->starts_at?->toDateTimeString() : $this->starts_at?->toIso8601String(),
            'endsAt' => $this->resource->isProductDiscount() ? $this->ends_at?->toDateTimeString() : $this->ends_at?->toIso8601String(),
            'isActive' => $this->is_active,
            'startsAtIso' => $this->starts_at?->toIso8601String(),
            'endsAtIso' => $this->ends_at?->toIso8601String(),
            'restaurant' => $this->whenLoaded('restaurant', fn () => [
                'id' => $this->restaurant->id,
                'name' => $this->restaurant->name,
            ]),
            'products' => $this->whenLoaded('products'),
            'rewardProducts' => $this->whenLoaded('rewardProducts'),
            'createdAt' => $this->created_at->toDateTimeString(),
            'updatedAt' => $this->updated_at->toDateTimeString(),
        ];
    }
}