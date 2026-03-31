<?php

declare(strict_types=1);

namespace Modules\User\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Modules\User\Http\Requests\RestaurantHomeNearestRestaurantsRequest;
use Modules\User\Http\Resources\UserRestaurantNearestRestaurantResource;
use Modules\User\Services\UserRestaurantNearestRestaurantsService;

final class UserRestaurantHomeNearestRestaurantsController
{
    public function __construct(
        private UserRestaurantNearestRestaurantsService $nearestRestaurantsService,
    ) {}

    public function __invoke(RestaurantHomeNearestRestaurantsRequest $request): JsonResponse
    {
        $restaurants = $this->nearestRestaurantsService->nearestForHome($request);

        return response()->json([
            'nearestRestaurants' => UserRestaurantNearestRestaurantResource::collection($restaurants),
        ]);
    }
}
