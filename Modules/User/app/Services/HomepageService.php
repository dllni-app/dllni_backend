<?php

declare(strict_types=1);

namespace Modules\User\Services;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Resturants\Models\Favorite;
use Modules\Supermarket\Enums\SmOrderStatus;
use Modules\Supermarket\Models\SmOffer;
use Modules\Supermarket\Models\SmStore;
use Modules\User\Http\Resources\UserSupermarketHomeOfferResource;
use Modules\User\Http\Resources\UserSupermarketHomeStoreResource;

final class HomepageService
{
    private const int MOST_REQUESTED_MIN_ORDERS_LAST_30_DAYS = 5;

    public static function mostRequestedMinOrders(): int
    {
        return self::MOST_REQUESTED_MIN_ORDERS_LAST_30_DAYS;
    }

    /**
     * @return array<string, mixed>
     */
    public function supermarketFeaturedOffers(Request $request): array
    {
        $now = CarbonImmutable::now();
        $limit = $request->integer('limit', 10);

        $supermarketOffers = SmOffer::query()
            ->where('is_active', true)
            ->where(fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>=', $now))
            ->latest('starts_at')
            ->with('store')
            ->limit($limit)
            ->get();

        return [
            'offers' => UserSupermarketHomeOfferResource::collection($supermarketOffers),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function supermarketNearbyStores(Request $request): array
    {
        $now = CarbonImmutable::now();
        $limit = $request->integer('limit', 15);
        $latitude = $request->validated('latitude');
        $longitude = $request->validated('longitude');
        $hasCoords = is_numeric($latitude) && is_numeric($longitude);
        $driver = DB::connection()->getDriverName();
        $useHaversine = $hasCoords && $driver !== 'sqlite';
        $since = $now->subDays(30);

        $query = SmStore::query()
            ->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('suspension_until')->orWhere('suspension_until', '<=', $now))
            ->with(['categories' => fn ($q) => $q->where('is_active', true)])
            ->withCount([
                'orders as popular_orders_count' => fn ($q) => $q
                    ->where('status', SmOrderStatus::Completed->value)
                    ->where('created_at', '>=', $since),
            ]);

        if ($useHaversine) {
            $lat = (float) $latitude;
            $lng = (float) $longitude;

            $query
                ->select('sm_stores.*')
                ->whereNotNull('sm_stores.latitude')
                ->whereNotNull('sm_stores.longitude')
                ->selectRaw(
                    '(6371 * acos(cos(radians(?)) * cos(radians(sm_stores.latitude)) * cos(radians(sm_stores.longitude) - radians(?)) + sin(radians(?)) * sin(radians(sm_stores.latitude)))) as distanceKm',
                    [$lat, $lng, $lat]
                )
                ->orderBy('distanceKm')
                ->orderByDesc('sm_stores.is_featured')
                ->orderByDesc('sm_stores.average_rating');
        } else {
            $query
                ->orderByDesc('is_featured')
                ->orderByDesc('average_rating')
                ->orderByDesc('id');
        }

        $storesNearby = $query->limit($limit)->get();

        $user = $request->user('sanctum');
        $this->attachFavoriteFlags($storesNearby, $user);
        $this->attachDiscountBadges($storesNearby);

        return [
            'stores' => UserSupermarketHomeStoreResource::collection($storesNearby),
        ];
    }

    /**
     * @param  Collection<int, SmStore>  $stores
     */
    private function attachFavoriteFlags(Collection $stores, ?User $user): void
    {
        if ($stores->isEmpty()) {
            return;
        }

        if ($user === null) {
            $stores->each(fn (SmStore $store) => $store->setAttribute('isFavoritedByUser', false));

            return;
        }

        $ids = $stores->modelKeys();

        $favoritedIds = Favorite::query()
            ->where('user_id', $user->id)
            ->where('favorable_type', SmStore::class)
            ->whereIn('favorable_id', $ids)
            ->pluck('favorable_id')
            ->flip();

        $stores->each(function (SmStore $store) use ($favoritedIds): void {
            $store->setAttribute('isFavoritedByUser', $favoritedIds->has($store->id));
        });
    }

    /**
     * @param  Collection<int, SmStore>  $stores
     */
    private function attachDiscountBadges(Collection $stores): void
    {
        if ($stores->isEmpty()) {
            return;
        }

        $storeIds = $stores->modelKeys();
        $now = CarbonImmutable::now();

        $offers = SmOffer::query()
            ->whereIn('store_id', $storeIds)
            ->where('is_active', true)
            ->where(fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>=', $now))
            ->orderByDesc('discount_percent')
            ->orderByDesc('discount_value')
            ->orderByDesc('starts_at')
            ->get()
            ->groupBy('store_id');

        $stores->each(function (SmStore $store) use ($offers): void {
            $offer = $offers->get($store->id)?->first();
            $store->setAttribute('discountOfferBadge', $this->formatOfferBadge($offer));
        });
    }

    private function formatOfferBadge(?SmOffer $offer): ?string
    {
        if ($offer === null) {
            return null;
        }

        if ($offer->discount_percent !== null) {
            $percent = rtrim(rtrim((string) $offer->discount_percent, '0'), '.');

            return "خصم {$percent}%";
        }

        if ($offer->discount_value !== null) {
            $value = rtrim(rtrim((string) $offer->discount_value, '0'), '.');
            $currency = config('app.currency', 'IQD');

            return "خصم {$value} {$currency}";
        }

        return null;
    }
}


