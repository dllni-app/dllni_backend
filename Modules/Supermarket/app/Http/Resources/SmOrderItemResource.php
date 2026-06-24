<?php

declare(strict_types=1);

namespace Modules\Supermarket\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Supermarket\Models\SmOrderItem;

/**
 * @mixin SmOrderItem
 */
final class SmOrderItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $product = $this->resource->relationLoaded('product') ? $this->product : null;
        $isAvailableInStock = $product !== null
            && (bool) $product->is_available
            && (int) $product->stock_quantity >= (int) $this->quantity;

        return [
            'id' => $this->id,
            'orderId' => $this->order_id,
            'order' => SmOrderResource::make($this->whenLoaded('order')),
            'productId' => $this->product_id,
            'product' => SmProductResource::make($this->whenLoaded('product')),
            'quantity' => $this->quantity,
            'unitPrice' => $this->unit_price,
            'totalPrice' => $this->total_price,
            'productName' => $this->product_name,
            'isAvailableInStock' => $isAvailableInStock,
            'createdAt' => $this->created_at?->toDateTimeString(),
            'updatedAt' => $this->updated_at?->toDateTimeString(),
        ];
    }
}
