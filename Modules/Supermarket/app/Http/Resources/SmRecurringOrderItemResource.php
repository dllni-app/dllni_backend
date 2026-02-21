<?php

declare(strict_types=1);

namespace Modules\Supermarket\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Supermarket\Models\SmRecurringOrderItem;

/**
 * @mixin SmRecurringOrderItem
 */
final class SmRecurringOrderItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'recurringOrderId' => $this->recurring_order_id,
            'recurringOrder' => SmRecurringOrderResource::make($this->whenLoaded('recurringOrder')),
            'masterProductId' => $this->master_product_id,
            'masterProduct' => $this->whenLoaded('masterProduct', fn () => [
                'id' => $this->masterProduct->id,
                'name' => $this->masterProduct->name,
            ]),
            'quantity' => $this->quantity,
            'unit' => $this->unit,
            'sortOrder' => $this->sort_order,
            'createdAt' => $this->created_at?->toDateTimeString(),
            'updatedAt' => $this->updated_at?->toDateTimeString(),
        ];
    }
}
