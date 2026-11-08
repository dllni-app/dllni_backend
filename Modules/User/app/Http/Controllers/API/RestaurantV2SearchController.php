<?php

declare(strict_types=1);

namespace Modules\User\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Modules\User\Http\Requests\RestaurantV2SearchRequest;
use Modules\User\Services\RestaurantV2CatalogService;

final class RestaurantV2SearchController
{
    public function __construct(private readonly RestaurantV2CatalogService $catalog) {}

    public function __invoke(RestaurantV2SearchRequest $request): JsonResponse
    {
        $filters = [
            'cuisineId' => $request->validated('cuisineId'),
            'categoryId' => $request->validated('categoryId'),
            'restaurantId' => $request->restaurantId(),
            'openNow' => $request->boolean('filter.openNow'),
            'hasOffers' => $request->boolean('filter.hasOffers'),
            'sort' => $request->validated('sort', 'relevance'),
        ];
        $result = $this->catalog->search(
            $request,
            $request->searchText(),
            $request->scope(),
            $filters,
            $request->pageSize(),
            $request->restaurantPage(),
            $request->dishPage(),
        );

        if ($request->scope() !== 'all') {
            return response()->json($result);
        }

        return response()->json(['data' => [
            'restaurants' => ['items' => $result['restaurants']['data'], 'meta' => $result['restaurants']['meta']],
            'dishes' => ['items' => $result['dishes']['data'], 'meta' => $result['dishes']['meta']],
        ]]);
    }
}
