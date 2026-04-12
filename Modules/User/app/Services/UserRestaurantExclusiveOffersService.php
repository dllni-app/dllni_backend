<?php

declare(strict_types=1);

namespace Modules\User\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Resturants\Models\Offer;
use Modules\User\Http\Requests\RestaurantHomeExclusiveOffersRequest;

final class UserRestaurantExclusiveOffersService
{
    /**
     * @return Collection<int, Offer>
     */
    public function exclusiveOffersNearYou(RestaurantHomeExclusiveOffersRequest $request): Collection
    {
        $now = CarbonImmutable::now();
        $limit = $request->integer('limit', 15);

        $latitude = $request->validated('latitude');
        $longitude = $request->validated('longitude');
        $hasCoords = is_numeric($latitude) && is_numeric($longitude);
        $driver = DB::connection()->getDriverName();
        $canUseHaversine = $hasCoords && $driver !== 'sqlite';

        $query = Offer::query()
            ->select('offers.*')
            ->join('restaurants', 'restaurants.id', '=', 'offers.restaurant_id')
            ->where('restaurants.is_active', true)
            ->where('offers.is_active', true)
            ->where(fn ($q) => $q->whereNull('offers.starts_at')->orWhere('offers.starts_at', '<=', $now))
            ->where(fn ($q) => $q->whereNull('offers.ends_at')->orWhere('offers.ends_at', '>=', $now))
            ->with(['restaurant.media']);

        if ($canUseHaversine) {
            $lat = (float) $latitude;
            $lng = (float) $longitude;

            $query
                ->whereNotNull('restaurants.latitude')
                ->whereNotNull('restaurants.longitude')
                ->selectRaw(
                    '(6371 * acos(cos(radians(?)) * cos(radians(restaurants.latitude)) * cos(radians(restaurants.longitude) - radians(?)) + sin(radians(?)) * sin(radians(restaurants.latitude)))) as distanceKm',
                    [$lat, $lng, $lat]
                )
                ->orderBy('distanceKm')
                ->orderByDesc('restaurants.is_featured')
                ->orderByDesc('restaurants.average_rating');
        } else {
            $query
                ->orderByDesc('restaurants.is_featured')
                ->orderByDesc('restaurants.average_rating')
                ->orderByDesc('offers.starts_at')
                ->orderByDesc('offers.id');
        }

        return $query->limit($limit)->with(['products', 'restaurant'])->get();
    }
}
