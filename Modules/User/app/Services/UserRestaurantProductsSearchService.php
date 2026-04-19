<?php

declare(strict_types=1);

namespace Modules\User\Services;

use Carbon\CarbonImmutable;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Modules\Resturants\Enums\OrderStatus;
use Modules\Resturants\Models\Product;
use Modules\Resturants\Services\RestaurantSemanticProductSearchService;
use Modules\User\Http\Requests\UserRestaurantProductsSearchRequest;

final class UserRestaurantProductsSearchService
{
    public function __construct(
        private RestaurantSemanticProductSearchService $semanticSearchService,
    ) {}

    public function search(UserRestaurantProductsSearchRequest $request): LengthAwarePaginator
    {
        $perPage = $request->getPerPage();
        $page = $request->getPage();
        $restaurantId = $request->getRestaurantId();
        $categoryId = $request->getCategoryId();
        $text = $request->getText();

        if ($text !== null) {
            $semanticPaginator = $this->searchWithSemanticResults(
                restaurantId: $restaurantId,
                categoryId: $categoryId,
                text: $text,
                perPage: $perPage,
                page: $page,
                query: $request->query(),
            );

            if ($semanticPaginator !== null) {
                return $semanticPaginator;
            }
        }

        return $this->fallbackSearch(
            restaurantId: $restaurantId,
            categoryId: $categoryId,
            text: $text,
            perPage: $perPage,
            page: $page,
            query: $request->query(),
        );
    }

    private function searchWithSemanticResults(?int $restaurantId, ?int $categoryId, string $text, int $perPage, int $page, array $query): ?LengthAwarePaginator
    {
        $payload = [
            'query' => $text,
            'top_k' => max($perPage * $page, $perPage),
        ];

        if ($categoryId !== null) {
            $payload['category_id'] = (string) $categoryId;
        }

        if ($restaurantId !== null) {
            $payload['restaurant_id'] = (string) $restaurantId;
        }

        $results = $this->semanticSearchService->search($payload);

        if ($results === null) {
            return null;
        }

        if ($results === []) {
            return $this->paginateCollection(collect(), $perPage, $page, $query);
        }

        $ids = array_values(array_unique(array_map(static fn(array $row): int => (int) $row['id'], $results)));
        $products = $this->baseQuery($restaurantId, $categoryId)
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('id');

        $ordered = collect($results)
            ->map(function (array $row) use ($products): ?Product {
                $product = $products->get($row['id']);

                if (! $product instanceof Product) {
                    return null;
                }

                $product->setAttribute('semantic_score', $row['score']);

                return $product;
            })
            ->filter(fn($item): bool => $item instanceof Product)
            ->values();

        return $this->paginateCollection($ordered, $perPage, $page, $query);
    }

    private function fallbackSearch(?int $restaurantId, ?int $categoryId, ?string $text, int $perPage, int $page, array $query): LengthAwarePaginator
    {
        $productsQuery = $this->baseQuery($restaurantId, $categoryId);

        if ($text !== null) {
            $escaped = addcslashes($text, '%_\\');

            $productsQuery->where(function ($query) use ($escaped): void {
                $query->where('name', 'like', "%{$escaped}%")
                    ->orWhere('description', 'like', "%{$escaped}%");
            });

            $productsQuery->reorder()
                ->orderByRaw(
                    'CASE ' .
                        'WHEN name LIKE ? THEN 0 ' .
                        'WHEN name LIKE ? THEN 1 ' .
                        'ELSE 2 END',
                    [$escaped . '%', '%' . $escaped . '%']
                )
                ->orderByDesc('created_at');
        }

        return $productsQuery
            ->paginate(perPage: $perPage, page: $page)
            ->appends($query);
    }

    private function baseQuery(?int $restaurantId, ?int $categoryId)
    {
        $since = CarbonImmutable::now()->subDays(30);

        $query = Product::query()
            ->where('is_available', true)
            ->whereHas('restaurant', fn($restaurantQuery) => $restaurantQuery->where('is_active', true))
            ->withCount([
                'orderItems as popular_orders_count' => fn($orderItemQuery) => $orderItemQuery
                    ->whereHas('order', fn($orderQuery) => $orderQuery
                        ->whereIn('status', [
                            OrderStatus::Completed->value,
                            OrderStatus::PickedUp->value,
                        ])
                        ->where('created_at', '>=', $since)),
            ])
            ->with([
                'offers' => function ($offerQuery) {
                    $offerQuery->where('is_active', true)
                        ->where(function ($nestedQuery) {
                            $nestedQuery->whereNull('ends_at')
                                ->orWhere('ends_at', '>', now());
                        });
                },
                'restaurant' => fn($restaurantQuery) => $restaurantQuery->select(['id', 'name', 'city', 'district']),
                'category' => fn($categoryQuery) => $categoryQuery->select(['id', 'name']),
                'media',
            ]);

        if ($categoryId !== null) {
            $query->where('category_id', $categoryId);
        }

        if ($restaurantId !== null) {
            $query->where('restaurant_id', $restaurantId);
        }

        return $query;
    }

    /**
     * @param  Collection<int, Product>  $items
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
