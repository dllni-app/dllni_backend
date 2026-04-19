<?php

declare(strict_types=1);

namespace Modules\Resturants\Http\Controllers\API;

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Modules\Resturants\Http\Requests\RestaurantSearchRequest;
use Modules\Resturants\Http\Resources\RestaurantSearchProductResource;
use Modules\Resturants\Models\Product;
use Modules\Resturants\Services\RestaurantSemanticProductSearchService;
use Modules\Resturants\Support\RestaurantOwnerContext;

final class RestaurantSearchController
{
    public function __construct(
        private readonly RestaurantSemanticProductSearchService $semanticSearchService,
    ) {}

    public function __invoke(RestaurantSearchRequest $request, RestaurantOwnerContext $context): AnonymousResourceCollection
    {
        $restaurant = $context->restaurant();

        $semanticQuery = $this->resolveSemanticQuery($request);

        if ($semanticQuery !== null) {
            $semanticPaginator = $this->semanticSearch($request, (int) $restaurant->id, $semanticQuery);

            if ($semanticPaginator !== null) {
                return RestaurantSearchProductResource::collection($semanticPaginator);
            }
        }

        return RestaurantSearchProductResource::collection(
            $this->fallbackSearch($request, (int) $restaurant->id)
        );
    }

    private function resolveSemanticQuery(RestaurantSearchRequest $request): ?string
    {
        $query = data_get($request->validated(), 'filter.search');

        if (! is_string($query)) {
            return null;
        }

        $trimmed = mb_trim($query);

        return $trimmed !== '' ? $trimmed : null;
    }

    private function semanticSearch(RestaurantSearchRequest $request, int $restaurantId, string $query): ?LengthAwarePaginator
    {
        $perPage = $request->integer('perPage', 10);
        $page = max(1, $request->integer('page', 1));

        $payload = [
            'query' => $query,
            'restaurant_id' => (string) $restaurantId,
            'top_k' => $request->integer('top_k', max($perPage * $page, $perPage)),
        ];

        $categoryId = $request->input('filter.categoryId');
        if (is_numeric($categoryId)) {
            $payload['category_id'] = (string) $categoryId;
        }

        $minPrice = $request->input('filter.minPrice');
        if (is_numeric($minPrice)) {
            $payload['price_min'] = (float) $minPrice;
        }

        $maxPrice = $request->input('filter.maxPrice');
        if (is_numeric($maxPrice)) {
            $payload['price_max'] = (float) $maxPrice;
        }

        $isFeatured = $request->input('filter.isFeatured');
        if ($isFeatured !== null) {
            $payload['is_featured'] = filter_var($isFeatured, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        }

        $isAvailable = $request->input('filter.isAvailable');
        if ($isAvailable !== null) {
            $payload['is_available'] = filter_var($isAvailable, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        } else {
            $payload['is_available'] = true;
        }

        $results = $this->semanticSearchService->search($payload);

        if ($results === null) {
            return null;
        }

        if ($results === []) {
            return $this->paginateCollection(collect(), $perPage, $page, $request->query());
        }

        $ids = array_values(array_unique(array_map(static fn (array $row): int => (int) $row['id'], $results)));

        $productsQuery = Product::query()
            ->whereIn('id', $ids)
            ->with(['restaurant', 'category']);

        if (! $request->has('filter.isAvailable')) {
            $productsQuery->where('is_available', true);
        }

        $productsQuery->whereHas('restaurant', static function ($query) use ($restaurantId): void {
            $query->where('id', $restaurantId)
                ->where('is_active', true);
        });

        $filters = (array) data_get($request->validated(), 'filter', []);

        if (isset($filters['categoryId']) && is_numeric($filters['categoryId'])) {
            $productsQuery->where('category_id', (int) $filters['categoryId']);
        }

        if (array_key_exists('isFeatured', $filters)) {
            $productsQuery->where('is_featured', filter_var($filters['isFeatured'], FILTER_VALIDATE_BOOLEAN));
        }

        if (array_key_exists('isAvailable', $filters)) {
            $productsQuery->where('is_available', filter_var($filters['isAvailable'], FILTER_VALIDATE_BOOLEAN));
        }

        if (array_key_exists('lowStock', $filters) && filter_var($filters['lowStock'], FILTER_VALIDATE_BOOLEAN)) {
            $productsQuery->whereColumn('stock_quantity', '<=', 'low_stock_threshold');
        }

        if (isset($filters['masterProductId']) && is_numeric($filters['masterProductId'])) {
            $productsQuery->where('master_product_id', (int) $filters['masterProductId']);
        }

        if (isset($filters['minPrice']) && is_numeric($filters['minPrice'])) {
            $productsQuery->where('price', '>=', (float) $filters['minPrice']);
        }

        if (isset($filters['maxPrice']) && is_numeric($filters['maxPrice'])) {
            $productsQuery->where('price', '<=', (float) $filters['maxPrice']);
        }

        if (array_key_exists('hasDiscount', $filters) && filter_var($filters['hasDiscount'], FILTER_VALIDATE_BOOLEAN)) {
            $productsQuery->whereNotNull('discounted_price')
                ->whereColumn('discounted_price', '<', 'price');
        }

        $products = $productsQuery->get()->keyBy('id');

        $ordered = collect($results)
            ->map(function (array $row) use ($products): ?Product {
                $product = $products->get($row['id']);

                if (! $product instanceof Product) {
                    return null;
                }

                $product->setAttribute('semantic_score', $row['score']);

                return $product;
            })
            ->filter(fn ($item): bool => $item instanceof Product)
            ->values();

        $ordered = $this->applyExplicitSortIfRequested($ordered, $request->validated('sort'));

        return $this->paginateCollection($ordered, $perPage, $page, $request->query());
    }

    private function fallbackSearch(RestaurantSearchRequest $request, int $restaurantId): LengthAwarePaginator
    {
        $productQuery = Product::getQuery()
            ->with(['restaurant', 'category']);

        if (! $request->has('filter.isAvailable')) {
            $productQuery->where('is_available', true);
        }

        $productQuery->whereHas('restaurant', static function ($query) use ($restaurantId): void {
            $query->where('id', $restaurantId)
                ->where('is_active', true);
        });

        if (! $request->filled('sort')) {
            $search = (string) data_get($request->validated(), 'filter.search', '');

            if ($search !== '') {
                $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search);
                $namePrefix = $escaped.'%';
                $nameContains = '%'.$escaped.'%';

                $productQuery->reorder()
                    ->orderByRaw(
                        'CASE '.
                        'WHEN name LIKE ? THEN 0 '.
                        'WHEN name LIKE ? THEN 1 '.
                        'ELSE 2 END',
                        [$namePrefix, $nameContains]
                    )
                    ->orderByDesc('is_featured')
                    ->orderByDesc('created_at');
            }
        }

        return $productQuery->paginate($request->get('perPage', 10));
    }

    /**
     * @param  Collection<int, Product>  $items
     */
    private function applyExplicitSortIfRequested(Collection $items, mixed $sort): Collection
    {
        if (! is_string($sort) || $sort === '') {
            return $items;
        }

        $isDesc = str_starts_with($sort, '-');
        $field = ltrim($sort, '-');

        $sorted = match ($field) {
            'name' => $isDesc
                ? $items->sortByDesc(fn (Product $product): string => mb_strtolower((string) $product->name))
                : $items->sortBy(fn (Product $product): string => mb_strtolower((string) $product->name)),
            'price' => $isDesc
                ? $items->sortByDesc(fn (Product $product): float => (float) ($product->price ?? 0))
                : $items->sortBy(fn (Product $product): float => (float) ($product->price ?? 0)),
            'createdAt' => $isDesc
                ? $items->sortByDesc(fn (Product $product): int => (int) $product->created_at?->getTimestamp())
                : $items->sortBy(fn (Product $product): int => (int) $product->created_at?->getTimestamp()),
            default => $items,
        };

        return $sorted->values();
    }

    /**
     * @param  Collection<int, Product>  $items
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
}
