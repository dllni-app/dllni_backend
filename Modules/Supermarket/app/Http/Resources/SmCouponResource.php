<?php

declare(strict_types=1);

namespace Modules\Supermarket\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Supermarket\Models\SmCoupon;

/**
 * @mixin SmCoupon
 */
final class SmCouponResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'storeId' => $this->store_id,
            'store' => SmStoreResource::make($this->whenLoaded('store')),
            'code' => $this->code,
            'type' => $this->type,
            'value' => $this->value,
            'percent' => $this->percent,
            'minOrderAmount' => $this->min_order_amount,
            'maxDiscountAmount' => $this->max_discount_amount,
            'usageLimit' => $this->usage_limit,
            'usedCount' => $this->used_count,
            'startsAt' => $this->starts_at?->toDateTimeString(),
            'endsAt' => $this->ends_at?->toDateTimeString(),
            'isActive' => $this->is_active,
            'createdAt' => $this->created_at?->toDateTimeString(),
            'updatedAt' => $this->updated_at?->toDateTimeString(),
        ];
    }
}
