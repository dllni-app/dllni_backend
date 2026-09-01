<?php

declare(strict_types=1);

namespace Modules\User\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Resturants\Models\Product;
use Modules\User\Services\UserRestaurantProductPopularityService;

/**
 * @mixin Product
 */
final class UserRestaurantProductWithOffersResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var Product $product */
        $product = $this->resource;
        $attributes = $product->getAttributes();

        $price = $product->price !== null ? (float) $product->price : null;
        $displayPrice = $price === null ? null : $product->effectivePrice();
        $hasDiscount = $price !== null && $displayPrice !== null && $displayPrice < $price;

        $activeOffers = $product->activeOffersNow();
        $popularOrdersCount = (int) ($attributes['popular_orders_count'] ?? 0);

        return [
            'id' => $product->id,
            'name' => $product->name,
            'description' => $product->description,
            'displayPrice' => $displayPrice,
            'discountedPrice' => $hasDiscount ? $displayPrice : null,
            'originalPrice' => $hasDiscount ? $price : null,
            'hasDiscount' => $hasDiscount,
            'currency' => config('app.currency', 'IQD'),
            'isAvailable' => $product->is_available,
            'isFavorite' => (bool) ($attributes['isFavoritedByUser'] ?? false),
            'isMostOrdered' => $popularOrdersCount >= UserRestaurantProductPopularityService::mostOrderedMinOrders(),
            'popularOrdersCount' => $popularOrdersCount,
            'cartQuantity' => (int) ($attributes['cartQuantity'] ?? 0),
            'primaryImageUrl' => $product->getFirstMediaUrl('primary-image') ?: null,
            'restaurant' => $product->relationLoaded('restaurant') && $product->restaurant !== null ? [
                'id' => $product->restaurant->id,
                'name' => $product->restaurant->name,
                'city' => $product->restaurant->city,
                'district' => $product->restaurant->district,
            ] : null,
            'category' => $product->relationLoaded('category') && $product->category !== null ? [
                'id' => $product->category->id,
                'name' => $product->category->name,
            ] : null,
            'activeOffers' => $activeOffers->isNotEmpty()
                ? UserRestaurantProductOfferResource::collection($activeOffers)
                : [],
            'createdAt' => $product->created_at->toDateTimeString(),
        ];
    }
}
