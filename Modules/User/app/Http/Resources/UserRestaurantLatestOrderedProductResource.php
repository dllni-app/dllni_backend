<?php

declare(strict_types=1);

namespace Modules\User\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Resturants\Models\OrderItem;

/**
 * @mixin OrderItem
 */
final class UserRestaurantLatestOrderedProductResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var OrderItem $line */
        $line = $this->resource;

        $product = $line->product;
        $restaurant = $product->restaurant;

        $price = $product->price !== null ? (float) $product->price : null;
        $discounted = $product->discounted_price !== null ? (float) $product->discounted_price : null;
        $hasDiscount = $price !== null && $discounted !== null && $discounted < $price;
        $displayPrice = $hasDiscount ? $discounted : $price;

        $orderedAt = $line->order->completed_at ?? $line->order->created_at;

        return [
            'productId' => $product->id,
            'productName' => $product->name,
            'restaurantId' => $restaurant->id,
            'restaurantName' => $restaurant->name,
            'displayPrice' => $displayPrice,
            'originalPrice' => $hasDiscount ? $price : null,
            'lastOrderedLineUnitPrice' => $line->unit_price !== null ? (float) $line->unit_price : null,
            'currency' => config('app.currency', 'IQD'),
            'primaryImageUrl' => $product->getFirstMediaUrl('primary-image') ?: null,
            'lastOrderId' => $line->order_id,
            'lastOrderedAt' => $orderedAt->toDateTimeString(),
        ];
    }
}
