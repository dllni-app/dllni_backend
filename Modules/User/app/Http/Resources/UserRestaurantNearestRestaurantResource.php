<?php

declare(strict_types=1);

namespace Modules\User\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Resturants\Models\Offer;
use Modules\Resturants\Models\Restaurant;
use Modules\User\Services\UserRestaurantNearestRestaurantsService;

/**
 * @mixin Restaurant
 */
final class UserRestaurantNearestRestaurantResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var Restaurant $restaurant */
        $restaurant = $this->resource;

        $prep = (int) $restaurant->estimated_preparation_time;
        $deliveryMin = max(5, $prep - 5);
        $deliveryMax = $prep + 15;

        $distanceKm = array_key_exists('distanceKm', $restaurant->getAttributes())
            ? round((float) $restaurant->getAttribute('distanceKm'), 2)
            : null;

        $popularCount = (int) ($restaurant->popular_orders_count ?? 0);

        return [
            'id' => $restaurant->id,
            'name' => $restaurant->name,
            'slug' => $restaurant->slug,
            'rating' => $restaurant->average_rating !== null
                ? round((float) $restaurant->average_rating, 1)
                : null,
            'primaryImageUrl' => $restaurant->getFirstMediaUrl('primary-image') ?: null,
            'cuisineNames' => $restaurant->relationLoaded('cuisineTypes')
                ? $restaurant->cuisineTypes->pluck('name')->values()->all()
                : [],
            'cuisineSummary' => $restaurant->relationLoaded('cuisineTypes')
                ? $restaurant->cuisineTypes->pluck('name')->implode(' • ')
                : '',
            'distanceKm' => $distanceKm,
            'distanceUnit' => $distanceKm !== null ? 'km' : null,
            'estimatedDeliveryMinutesMin' => $deliveryMin,
            'estimatedDeliveryMinutesMax' => $deliveryMax,
            'discountOfferBadge' => $this->formatDiscountBadge($restaurant->primaryActiveOffer),
            'isMostRequested' => $popularCount >= UserRestaurantNearestRestaurantsService::mostRequestedMinOrders(),
            'popularOrdersCount' => $popularCount,
            'isFavorited' => (bool) ($restaurant->getAttribute('isFavoritedByUser') ?? false),
            'deliveryFee' => null,
            'isFreeDelivery' => null,
            'currency' => config('app.currency', 'IQD'),
        ];
    }

    private function formatDiscountBadge(?Offer $offer): ?string
    {
        if ($offer === null) {
            return null;
        }

        return $offer->listingBadgeText();
    }
}
