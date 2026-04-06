<?php

declare(strict_types=1);

namespace Modules\User\Http\Controllers\API;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Modules\Resturants\Models\Favorite;
use Modules\Supermarket\Http\Resources\SmStoreResource;
use Modules\Supermarket\Models\SmStore;
use Modules\User\Http\Requests\DiscoverSupermarketStoresRequest;

final class SmStoresIndexController
{
    public function __invoke(DiscoverSupermarketStoresRequest $request): AnonymousResourceCollection
    {
        $now = CarbonImmutable::now();

        $query = SmStore::getQuery()
            ->where('is_active', true)
            ->with('owner', 'highestDiscountOffer')
            ->where(fn ($q) => $q->whereNull('suspension_until')->orWhere('suspension_until', '<=', $now));

        $search = $request->validated('search');
        if (is_string($search) && $search !== '') {
            $query->search($search);
        }

        $sort = $request->validated('sort') ?? 'rating';

        match ($sort) {
            'nearest', 'nearestBy' => $this->applyNearestSort($query, $request),
            'alphabet', 'alphabetical' => $query->orderBy('name')->orderByDesc('is_featured'),
            default => $query->orderByDesc('average_rating')->orderByDesc('is_featured'),
        };

        $stores = $query->paginate($request->integer('perPage', 20));
        $this->attachFavoriteFlags($stores->getCollection(), $request->user('sanctum'));

        return SmStoreResource::collection($stores);
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

        $favoritedIds = Favorite::query()
            ->where('user_id', $user->id)
            ->where('favorable_type', SmStore::class)
            ->whereIn('favorable_id', $stores->modelKeys())
            ->pluck('favorable_id')
            ->flip();

        $stores->each(function (SmStore $store) use ($favoritedIds): void {
            $store->setAttribute('isFavoritedByUser', $favoritedIds->has($store->id));
        });
    }

    private function applyNearestSort($query, DiscoverSupermarketStoresRequest $request): void
    {
        $latitude = $request->validated('latitude');
        $longitude = $request->validated('longitude');

        if (! is_numeric($latitude) || ! is_numeric($longitude)) {
            $query->orderByDesc('average_rating')->orderByDesc('is_featured');

            return;
        }

        $lat = (float) $latitude;
        $lng = (float) $longitude;

        $query
            ->select('sm_stores.*')
            ->selectRaw(
                '(6371 * acos(cos(radians(?)) * cos(radians(latitude)) * cos(radians(longitude) - radians(?)) + sin(radians(?)) * sin(radians(latitude)))) as distanceKm',
                [$lat, $lng, $lat]
            )
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->orderBy('distanceKm');
    }
}
