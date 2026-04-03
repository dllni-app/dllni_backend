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
            'id' => $this->id,
            'restaurantId' => $this->restaurant_id,
            'name' => $this->name,
            'discountType' => $this->discount_type?->value ?? $this->discount_type,
            'discountValue' => $this->discount_value ? (float) $this->discount_value : null,
            'imageUrl' => $imageUrl,
            'startsAt' => $this->starts_at?->toDateTimeString(),
            'endsAt' => $this->ends_at?->toDateTimeString(),
            'isActive' => $this->is_active,
            'restaurant' => $this->whenLoaded('restaurant', fn () => [
                'id' => $this->restaurant->id,
                'name' => $this->restaurant->name,
            ]),
            'products' => $this->whenLoaded('products'),
            'createdAt' => $this->created_at->toDateTimeString(),
            'updatedAt' => $this->updated_at->toDateTimeString(),
        ];
    }
}
