<?php

declare(strict_types=1);

namespace Modules\Supermarket\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Supermarket\Models\SmSmartListItem;

/**
 * @mixin SmSmartListItem
 */
final class SmSmartListItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'smartListId' => $this->smart_list_id,
            'smartList' => SmSmartListResource::make($this->whenLoaded('smartList')),
            'masterProductId' => $this->master_product_id,
            'masterProduct' => $this->whenLoaded('masterProduct', fn () => [
                'id' => $this->masterProduct->id,
                'name' => $this->masterProduct->name,
            ]),
            'quantity' => $this->quantity,
            'unit' => $this->unit,
            'sortOrder' => $this->sort_order,
            'isIncluded' => (bool) ($this->is_included ?? true),
            'createdAt' => $this->created_at?->toDateTimeString(),
            'updatedAt' => $this->updated_at?->toDateTimeString(),
        ];
    }
}
