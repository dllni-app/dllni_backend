<?php

declare(strict_types=1);

namespace Modules\User\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Resturants\Models\Restaurant;
use Modules\User\Services\RestaurantV2Availability;

/** @mixin Restaurant */
final class RestaurantV2RestaurantResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var Restaurant $restaurant */
        $restaurant = $this->resource;
        // Review is the customer-visible source used by the existing V1 detail endpoint.
        // Stored restaurants.average_rating can be stale and is not authoritative here.
        $average = $restaurant->getAttribute('reviews_avg_rating');
        $reviewCount = (int) ($restaurant->getAttribute('reviews_count') ?? 0);
        $preparationMin = $restaurant->estimated_preparation_time_min;
        $preparationMax = $restaurant->estimated_preparation_time_max ?? $restaurant->estimated_preparation_time;
        $thumbnail = (bool) $request->attributes->get('restaurant_home_thumbnail', false);
        $image = \App\Support\ImageVariants::url($restaurant, 'primary-image', $thumbnail);
        $offer = $restaurant->relationLoaded('offers') ? $restaurant->offers->first() : null;

        return [
            'id' => (int) $restaurant->id,
            'slug' => $restaurant->slug,
            'name' => $restaurant->name,
            'description' => $restaurant->description,
            'address' => $restaurant->address,
            'city' => $restaurant->city,
            'district' => $restaurant->district,
            'latitude' => $restaurant->latitude !== null ? (float) $restaurant->latitude : null,
            'longitude' => $restaurant->longitude !== null ? (float) $restaurant->longitude : null,
            'imageUrl' => $image !== '' ? $image : null,
            'bannerUrl' => \App\Support\ImageVariants::url($restaurant, 'banner-image', $thumbnail),
            'cuisines' => $restaurant->relationLoaded('cuisineTypes')
                ? $restaurant->cuisineTypes->map(fn ($cuisine): array => [
                    'id' => (int) $cuisine->id,
                    'name' => $cuisine->name,
                    'slug' => $cuisine->slug,
                    'imageUrl' => $cuisine->iconUrl(),
                ])->values()->all()
                : [],
            'rating' => $average !== null && $reviewCount > 0
                ? ['average' => number_format((float) $average, 2, '.', ''), 'total' => $reviewCount]
                : null,
            'preparationTime' => $preparationMin !== null || $preparationMax !== null
                ? ['min' => $preparationMin !== null ? (int) $preparationMin : null, 'max' => $preparationMax !== null ? (int) $preparationMax : null]
                : null,
            'distanceKm' => array_key_exists('distanceKm', $restaurant->getAttributes()) && $restaurant->distanceKm !== null
                ? round((float) $restaurant->distanceKm, 2)
                : null,
            'minimumOrderAmount' => $restaurant->minimum_order_amount !== null
                ? number_format((float) $restaurant->minimum_order_amount, 2, '.', '')
                : null,
            'currency' => config('app.currency', 'SYP'),
            'isActive' => (bool) $restaurant->is_active,
            'isOpen' => RestaurantV2Availability::isOpen($restaurant),
            'isTemporarilyClosed' => (bool) $restaurant->is_temporarily_closed,
            'listingOffer' => $offer !== null ? [
                ...\Modules\User\Services\RestaurantPromotionCatalog::summary($offer),
                'id' => (int) $offer->id,
                'title' => $offer->name,
                'description' => $offer->description,
                'discountType' => $offer->isProductDiscount() ? $offer->discount_type?->value : null,
                'discountValue' => $offer->isProductDiscount() ? number_format((float) $offer->discount_value, 2, '.', '') : null,
                'startsAt' => $offer->starts_at?->toIso8601String(),
                'endsAt' => $offer->ends_at?->toIso8601String(),
            ] : null,
            'isFavoritedByUser' => (bool) ($restaurant->getAttribute('isFavoritedByUser') ?? false),
        ];
    }
}