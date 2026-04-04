<?php

declare(strict_types=1);

namespace Modules\User\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Modules\User\Http\Requests\RestaurantProductsByCategoryRequest;
use Modules\User\Http\Resources\UserRestaurantProductWithOffersResource;
use Modules\User\Services\UserRestaurantProductsByCategoryService;

final class UserRestaurantProductsByCategoryController
{
    public function __construct(
        private UserRestaurantProductsByCategoryService $service,
    ) {}

    public function __invoke(
        RestaurantProductsByCategoryRequest $request,
        int $category,
    ): JsonResponse {
        $products = $this->service->paginateProductsByCategory(
            categoryId: $category,
            perPage: $request->getPerPage(),
        );

        // Add isFavoritedByUser attribute for authenticated users
        $user = $request->user('sanctum');
        if ($user !== null) {
            $products->getCollection()->each(function ($product) use ($user) {
                $product->setAttribute('isFavoritedByUser', $user->favorites()
                    ->where('favorable_type', 'Modules\\Resturants\\Models\\Product')
                    ->where('favorable_id', $product->id)
                    ->exists());
            });
        }

        return response()->json(
            UserRestaurantProductWithOffersResource::collection($products),
        );
    }
}
