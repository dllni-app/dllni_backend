<?php

declare(strict_types=1);

namespace Modules\Supermarket\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Supermarket\Models\SmCommissionRule;

/**
 * @mixin SmCommissionRule
 */
final class SmCommissionRuleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'storeId' => $this->store_id,
            'store' => SmStoreResource::make($this->whenLoaded('store')),
            'commissionType' => $this->commission_type,
            'value' => $this->value,
            'minOrderAmount' => $this->min_order_amount,
            'maxCommissionAmount' => $this->max_commission_amount,
            'startsAt' => $this->starts_at?->toDateTimeString(),
            'endsAt' => $this->ends_at?->toDateTimeString(),
            'isDefault' => $this->is_default,
            'isActive' => $this->is_active,
            'createdAt' => $this->created_at?->toDateTimeString(),
            'updatedAt' => $this->updated_at?->toDateTimeString(),
        ];
    }
}
