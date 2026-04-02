<?php

declare(strict_types=1);

namespace Modules\User\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Supermarket\Models\SmStore;
use Modules\User\Services\HomepageService;

/**
 * @mixin SmStore
 */
final class UserSupermarketHomeStoreResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var SmStore $store */
        $store = $this->resource;

        $distanceKm = array_key_exists('distanceKm', $store->getAttributes())
            ? round((float) $store->getAttribute('distanceKm'), 2)
            : null;

        $categories = $store->relationLoaded('categories')
            ? $store->categories->pluck('name')->filter()->values()->all()
            : [];

        $popularCount = (int) ($store->popular_orders_count ?? 0);

        [$minMinutes, $maxMinutes] = $this->estimateDeliveryWindow($distanceKm);

        return [
            'id' => $store->id,
            'name' => $store->name,
            'slug' => $store->slug,
            'cover' => $store->cover,
            'logo' => $store->logo,
            'rating' => $store->average_rating !== null
                ? round((float) $store->average_rating, 1)
                : null,
            'categoryNames' => $categories,
            'categorySummary' => $categories !== []
                ? implode('، ', $categories)
                : '',
            'distanceKm' => $distanceKm,
            'distanceUnit' => $distanceKm !== null ? 'km' : null,
            'estimatedDeliveryMinutesMin' => $minMinutes,
            'estimatedDeliveryMinutesMax' => $maxMinutes,
            'discountOfferBadge' => $store->getAttribute('discountOfferBadge'),
            'isMostRequested' => $popularCount >= HomepageService::mostRequestedMinOrders(),
            'popularOrdersCount' => $popularCount,
            'isFavorited' => (bool) ($store->getAttribute('isFavoritedByUser') ?? false),
            'deliveryFee' => null,
            'isFreeDelivery' => null,
            'currency' => config('app.currency', 'IQD'),
        ];
    }

    /**
     * @return array{0: int|null, 1: int|null}
     */
    private function estimateDeliveryWindow(?float $distanceKm): array
    {
        if ($distanceKm === null) {
            return [null, null];
        }

        $min = max(10, (int) round(15 + ($distanceKm * 6)));
        $max = $min + 10;

        return [$min, $max];
    }
}
