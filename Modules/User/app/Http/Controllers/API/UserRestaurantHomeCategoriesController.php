<?php

declare(strict_types=1);

namespace Modules\User\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Modules\User\Http\Resources\UserRestaurantCuisineCategoryResource;
use Modules\User\Services\UserRestaurantHomeCategoriesService;

final class UserRestaurantHomeCategoriesController
{
    public function __construct(
        private UserRestaurantHomeCategoriesService $categoriesService,
    ) {}

    public function __invoke(): JsonResponse
    {
        $categories = $this->categoriesService->categoriesForHome();

        return response()->json([
            'categories' => UserRestaurantCuisineCategoryResource::collection($categories),
        ]);
    }
}
