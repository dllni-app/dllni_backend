<?php

declare(strict_types=1);

namespace Modules\User\Http\Controllers\API;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Pagination\LengthAwarePaginator;
use Modules\Resturants\Models\Favorite;
use Modules\Supermarket\Http\Resources\SmStoreResource;
use Modules\Supermarket\Models\SmStore;
use Modules\Supermarket\Services\SmSemanticStoreSearchService;
use Modules\User\Http\Requests\DiscoverSupermarketStoresRequest;

final class SmStoresIndexController
{
    public function __construct(
        private readonly SmSemanticStoreSearchService $semanticSearchService,
    ) {}

    public function __invoke(DiscoverSupermarketStoresRequest $request): AnonymousResourceCollection
    {
        $query = $this->resolveSemanticQuery($request);

        if ($query !== null) {
            $semanticPaginator = $this->semanticSearch($request, $query);

            if ($semanticPaginator !== null) {
                $this->attachFavoriteFlags($semanticPaginator->getCollection(), $request->user('sanctum'));

                return SmStoreResource::collection($semanticPaginator);
            }
        }

        return $this->fallbackSearch($request);
    }

    private function fallbackSearch(DiscoverSupermarketStoresRequest $request): AnonymousResourceCollection
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

    private function resolveSemanticQuery(DiscoverSupermarketStoresRequest $request): ?string
    {
        $validated = $request->validated();

        $semanticQuery = $validated['query'] ?? $validated['search'] ?? $validated['filter']['search'] ?? null;

        if (! is_string($semanticQuery)) {
            return null;
        }

        $trimmed = mb_trim($semanticQuery);

        return $trimmed !== '' ? $trimmed : null;
    }

    private function semanticSearch(DiscoverSupermarketStoresRequest $request, string $query): ?LengthAwarePaginator
    {
        $perPage = $request->integer('perPage', 20);
        $page = max(1, $request->integer('page', 1));

        $payload = [
            'query' => $query,
            'top_k' => $request->integer('top_k', max($perPage * $page, $perPage)),
        ];

        $isActive = $request->input('is_active', $request->input('filter.isActive'));
        if ($isActive !== null) {
            $payload['is_active'] = filter_var($isActive, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        }

        $isFeatured = $request->input('is_featured', $request->input('filter.isFeatured'));
        if ($isFeatured !== null) {
            $payload['is_featured'] = filter_var($isFeatured, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        }

        $averageRatingMin = $request->input('average_rating_min', $request->input('filter.averageRatingMin'));
        if (is_numeric($averageRatingMin)) {
            $payload['average_rating_min'] = (float) $averageRatingMin;
        }

        $results = $this->semanticSearchService->search($payload);

        if ($results === null) {
            return null;
        }

        if ($results === []) {
            return $this->paginateCollection(new Collection(), $perPage, $page, $request->query());
        }

        $ids = array_values(array_unique(array_map(static fn (array $row): int => (int) $row['id'], $results)));
        $now = CarbonImmutable::now();

        $stores = SmStore::query()
            ->whereIn('id', $ids)
            ->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('suspension_until')->orWhere('suspension_until', '<=', $now))
            ->with('owner', 'highestDiscountOffer')
            ->get()
            ->keyBy('id');

        $ordered = new Collection(collect($results)
            ->map(function (array $row) use ($stores): ?SmStore {
                $store = $stores->get($row['id']);

                if (! $store instanceof SmStore) {
                    return null;
                }

                $store->setAttribute('semantic_score', $row['score']);

                return $store;
            })
            ->filter(fn ($item): bool => $item instanceof SmStore)
            ->values()
            ->all());

        return $this->paginateCollection($ordered, $perPage, $page, $request->query());
    }

    /**
     * @param  Collection<int, SmStore>  $items
     * @param  array<string, mixed>  $query
     */
    private function paginateCollection(Collection $items, int $perPage, int $page, array $query): LengthAwarePaginator
    {
        $total = $items->count();
        $results = $items->slice(($page - 1) * $perPage, $perPage)->values();

        return new LengthAwarePaginator(
            $results,
            $total,
            $perPage,
            $page,
            [
                'path' => request()->url(),
                'query' => $query,
            ]
        );
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
