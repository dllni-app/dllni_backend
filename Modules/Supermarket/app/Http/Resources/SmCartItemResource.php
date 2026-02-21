<?php

declare(strict_types=1);

namespace Modules\Supermarket\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Supermarket\Models\SmCartItem;

/**
 * @mixin SmCartItem
 */
final class SmCartItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'cartId' => $this->cart_id,
            'cart' => SmCartResource::make($this->whenLoaded('cart')),
            'productId' => $this->product_id,
            'product' => SmProductResource::make($this->whenLoaded('product')),
            'quantity' => $this->quantity,
            'unitPrice' => $this->unit_price,
            'createdAt' => $this->created_at?->toDateTimeString(),
            'updatedAt' => $this->updated_at?->toDateTimeString(),
        ];
    }
}
