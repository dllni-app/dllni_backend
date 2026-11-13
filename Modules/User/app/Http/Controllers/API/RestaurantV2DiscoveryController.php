<?php

declare(strict_types=1);

namespace Modules\User\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Modules\User\Http\Requests\RestaurantV2DiscoveryRequest;
use Modules\User\Services\RestaurantV2CatalogService;

final class RestaurantV2DiscoveryController
{
    public function __construct(private readonly RestaurantV2CatalogService $catalog) {}

    public function __invoke(RestaurantV2DiscoveryRequest $request): JsonResponse
    {
        $filters = [
            'cuisineId' => $request->validated('cuisineId'),
            'categoryId' => $request->validated('categoryId'),
            'foodCategoryId' => $request->validated('foodCategoryId'),
            'openNow' => $request->boolean('filter.openNow'),
            'hasOffers' => $request->boolean('filter.hasOffers'),
            'sort' => $request->validated('sort', 'rating'),
        ];

        return response()->json($this->catalog->discovery($request, $filters, $request->pageSize(), $request->pageNumber()));
    }
}
