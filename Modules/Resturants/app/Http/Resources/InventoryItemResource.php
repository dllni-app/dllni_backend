<?php

declare(strict_types=1);

namespace Modules\Resturants\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Resturants\Models\InventoryItem;

/**
 * @mixin InventoryItem
 */
final class InventoryItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'restaurantId' => $this->restaurant_id,
            'name' => $this->name,
            'unit' => $this->unit,
            'quantity' => (float) $this->quantity,
            'minimumLimit' => (float) $this->minimum_limit,
            'unitCost' => (float) $this->unit_cost,
            'restaurant' => $this->whenLoaded('restaurant', fn () => [
                'id' => $this->restaurant->id,
                'name' => $this->restaurant->name,
            ]),
            'products' => $this->whenLoaded('products', fn () => $this->products->map(fn ($p) => [
                'id' => $p->id,
                'name' => $p->name,
                'quantityUsed' => (float) ($p->pivot->quantity_used ?? 1),
            ])->values()->all()),
            'createdAt' => $this->created_at->toDateTimeString(),
            'updatedAt' => $this->updated_at->toDateTimeString(),
        ];
    }
}
