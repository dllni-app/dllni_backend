<?php

declare(strict_types=1);

namespace Modules\User\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Modules\User\Http\Requests\RestaurantHomeSuggestedProductsRequest;
use Modules\User\Http\Resources\UserRestaurantSuggestedProductResource;
use Modules\User\Services\UserRestaurantSuggestedProductsService;

final class UserRestaurantHomeSuggestedProductsController
{
    public function __construct(
        private UserRestaurantSuggestedProductsService $suggestedProductsService,
    ) {}

    public function __invoke(RestaurantHomeSuggestedProductsRequest $request): JsonResponse
    {
        $products = $this->suggestedProductsService->suggestedForHome($request);

        return response()->json([
            'suggestedProducts' => UserRestaurantSuggestedProductResource::collection($products),
        ]);
    }
}
