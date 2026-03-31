<?php

declare(strict_types=1);

namespace Modules\User\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Modules\User\Http\Requests\RestaurantHomeLatestOrderedProductsRequest;
use Modules\User\Http\Resources\UserRestaurantLatestOrderedProductResource;
use Modules\User\Services\UserRestaurantLatestOrderedProductsService;

final class UserRestaurantHomeLatestOrderedProductsController
{
    public function __construct(
        private UserRestaurantLatestOrderedProductsService $latestOrderedProductsService,
    ) {}

    public function __invoke(RestaurantHomeLatestOrderedProductsRequest $request): JsonResponse
    {
        $user = $request->user();
        abort_if($user === null, 401);

        $items = $this->latestOrderedProductsService->latestOrderedItems($user, $request);

        return response()->json([
            'latestOrderedProducts' => UserRestaurantLatestOrderedProductResource::collection($items),
        ]);
    }
}
