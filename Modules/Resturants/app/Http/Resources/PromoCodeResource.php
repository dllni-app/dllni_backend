<?php

declare(strict_types=1);

namespace Modules\Resturants\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Resturants\Models\PromoCode;

/**
 * @mixin PromoCode
 */
final class PromoCodeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'restaurantId' => $this->restaurant_id,
            'code' => $this->code,
            'discountType' => $this->discount_type?->value ?? $this->discount_type,
            'discountValue' => $this->discount_value ? (float) $this->discount_value : null,
            'minOrderAmount' => $this->min_order_amount ? (float) $this->min_order_amount : null,
            'usageLimit' => $this->usage_limit,
            'usageCount' => $this->usage_count,
            'startsAt' => $this->starts_at?->toDateTimeString(),
            'endsAt' => $this->ends_at?->toDateTimeString(),
            'isActive' => $this->is_active,
            'restaurant' => $this->whenLoaded('restaurant', fn () => [
                'id' => $this->restaurant->id,
                'name' => $this->restaurant->name,
            ]),
            'createdAt' => $this->created_at->toDateTimeString(),
            'updatedAt' => $this->updated_at->toDateTimeString(),
        ];
    }
}
