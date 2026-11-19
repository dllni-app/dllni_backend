<?php

declare(strict_types=1);

namespace Modules\Supermarket\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Supermarket\Models\SmOffer;

/**
 * @mixin SmOffer
 */
final class SmOfferResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            ...$this->resource->promotionAttributes(),
            'offerProducts' => $this->whenLoaded('offerProducts', fn () => $this->offerProducts->map(fn ($p) => ['productId' => (int) $p->product_id, 'product' => ['id' => (int) $p->product_id, 'name' => $p->product?->name]])),
            'rewardProducts' => $this->whenLoaded('rewardProducts', fn () => $this->rewardProducts->map(fn ($p) => ['id' => (int) $p->id, 'name' => $p->name])),
            'id' => $this->id,
            'storeId' => $this->store_id,
            'store' => SmStoreResource::make($this->whenLoaded('store')),
            'name' => $this->name,
            'description' => $this->description,
            'offerType' => $this->offer_type,
            'discountValue' => $this->discount_value,
            'discountPercent' => $this->discount_percent,
            'startsAt' => $this->starts_at?->toDateTimeString(),
            'endsAt' => $this->ends_at?->toDateTimeString(),
            'isActive' => $this->is_active,
            'offerProductsCount' => (int) ($this->offer_products_count ?? 0),
            'affectedOrdersCount' => (int) ($this->promotion_type === 'buy_x_get_y' ? ($this->promotion_orders_count ?? 0) : ($this->affected_orders_count ?? 0)),
            'createdAt' => $this->created_at?->toDateTimeString(),
            'updatedAt' => $this->updated_at?->toDateTimeString(),
        ];
    }
}
