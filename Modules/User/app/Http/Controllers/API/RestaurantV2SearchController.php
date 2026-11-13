<?php

declare(strict_types=1);

namespace Modules\User\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Modules\User\Http\Requests\RestaurantV2SearchRequest;
use Modules\User\Services\RestaurantV2CatalogService;
use Modules\User\Services\RestaurantV2SmartIntent;

final class RestaurantV2SearchController
{
    public function __construct(private readonly RestaurantV2CatalogService $catalog) {}

    public function __invoke(RestaurantV2SearchRequest $request): JsonResponse
    {
        $filters = [
            'cuisineId' => $request->validated('cuisineId'),
            'categoryId' => $request->validated('categoryId'),
            'foodCategoryId' => $request->validated('foodCategoryId'),
            'minPrice' => $request->validated('minPrice'),
            'maxPrice' => $request->validated('maxPrice'),
            'area' => $request->validated('area'),
            'restaurantId' => $request->restaurantId(),
            'openNow' => $request->boolean('filter.openNow'),
            'hasOffers' => $request->boolean('filter.hasOffers'),
            'sort' => $request->validated('sort', 'relevance'),
        ];
        $text = $request->searchText();
        $interpretation = null;
        if ($request->isMethod('post')) {
            $resolved = app(RestaurantV2SmartIntent::class)->resolve($text, $filters, $request->coordinates() !== null);
            $text = $resolved['text'];
            $filters = $resolved['filters'];
            $interpretation = $resolved['interpretation'];
        }
        $request->attributes->set('restaurant_home_thumbnail', true);
        $result = $this->catalog->search(
            $request,
            $text,
            $request->scope(),
            $filters,
            $request->pageSize(),
            $request->restaurantPage(),
            $request->dishPage(),
        );

        if ($request->scope() !== 'all') {
            return response()->json($interpretation === null ? $result : $result + ['interpretation' => $interpretation]);
        }

        $body = ['data' => [
            'restaurants' => ['items' => $result['restaurants']['data'], 'meta' => $result['restaurants']['meta']],
            'dishes' => ['items' => $result['dishes']['data'], 'meta' => $result['dishes']['meta']],
        ]];
        if ($interpretation !== null) $body['interpretation'] = $interpretation;
        return response()->json($body);
    }
}
