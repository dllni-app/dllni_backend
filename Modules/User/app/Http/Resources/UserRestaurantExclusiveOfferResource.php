<?php

declare(strict_types=1);

namespace Modules\User\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Resturants\Http\Resources\ProductResource;
use Modules\Resturants\Http\Resources\RestaurantResource;
use Modules\Resturants\Models\Offer;

/**
 * @mixin Offer
 */
final class UserRestaurantExclusiveOfferResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var Offer $offer */
        $offer = $this->resource;

        $restaurant = $offer->restaurant;
        $distanceKm = array_key_exists('distanceKm', $offer->getAttributes())
            ? round((float) $offer->getAttribute('distanceKm'), 2)
            : null;

        return [
            'offerId' => $offer->id,
            'restaurantId' => $offer->restaurant_id,
            'restaurantName' => $restaurant->name,
            'offerBadgeText' => $offer->listingBadgeText(),
            'offerDescription' => $offer->name,
            'discountType' => $offer->discount_type?->value ?? $offer->discount_type,
            'discountValue' => $offer->discount_value !== null ? (float) $offer->discount_value : null,
            'urgencyTag' => $offer->listingUrgencyTag()?->value,
            'distanceKm' => $distanceKm,
            'distanceUnit' => $distanceKm !== null ? 'km' : null,
            'imageUrl' => $restaurant->getFirstMediaUrl('primary-image') ?: null,
            'restaurant' => RestaurantResource::make($this->whenLoaded('restaurant')),
            'products' => ProductResource::collection($this->whenLoaded('products')),
        ];
    }
}
