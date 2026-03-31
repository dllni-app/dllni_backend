<?php

declare(strict_types=1);

namespace Modules\User\Services;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Resturants\Enums\OrderStatus;
use Modules\Resturants\Models\Favorite;
use Modules\Resturants\Models\Restaurant;
use Modules\User\Http\Requests\RestaurantHomeNearestRestaurantsRequest;

final class UserRestaurantNearestRestaurantsService
{
    private const int MOST_REQUESTED_MIN_ORDERS_LAST_30_DAYS = 5;

    public static function mostRequestedMinOrders(): int
    {
        return self::MOST_REQUESTED_MIN_ORDERS_LAST_30_DAYS;
    }

    /**
     * @return Collection<int, Restaurant>
     */
    public function nearestForHome(RestaurantHomeNearestRestaurantsRequest $request): Collection
    {
        $limit = $request->integer('limit', 15);
        $latitude = $request->validated('latitude');
        $longitude = $request->validated('longitude');
        $hasCoords = is_numeric($latitude) && is_numeric($longitude);
        $driver = DB::connection()->getDriverName();
        $useHaversine = $hasCoords && $driver !== 'sqlite';

        $since = CarbonImmutable::now()->subDays(30);

        $query = Restaurant::query()
            ->where('is_active', true)
            ->with(['media', 'cuisineTypes', 'primaryActiveOffer'])
            ->withCount([
                'orders as popular_orders_count' => fn ($q) => $q
                    ->whereIn('status', [
                        OrderStatus::Completed->value,
                        OrderStatus::PickedUp->value,
                    ])
                    ->where('created_at', '>=', $since),
            ]);

        if ($useHaversine) {
            $lat = (float) $latitude;
            $lng = (float) $longitude;

            $query
                ->select('restaurants.*')
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
                ->orderByDesc('is_featured')
                ->orderByDesc('average_rating')
                ->orderByDesc('id');
        }

        $restaurants = $query->limit($limit)->get();

        $user = $request->user('sanctum');
        $this->attachFavoriteFlags($restaurants, $user);

        return $restaurants;
    }

    /**
     * @param  Collection<int, Restaurant>  $restaurants
     */
    private function attachFavoriteFlags(Collection $restaurants, ?User $user): void
    {
        if ($user === null || $restaurants->isEmpty()) {
            $restaurants->each(fn (Restaurant $r) => $r->setAttribute('isFavoritedByUser', false));

            return;
        }

        $ids = $restaurants->modelKeys();

        $favoritedIds = Favorite::query()
            ->where('user_id', $user->id)
            ->where('favorable_type', Restaurant::class)
            ->whereIn('favorable_id', $ids)
            ->pluck('favorable_id')
            ->flip();

        $restaurants->each(function (Restaurant $r) use ($favoritedIds): void {
            $r->setAttribute('isFavoritedByUser', $favoritedIds->has($r->id));
        });
    }
}
