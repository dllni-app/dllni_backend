<?php

declare(strict_types=1);

namespace Modules\User\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Modules\User\Http\Requests\RestaurantProductsWithOffersRequest;
use Modules\User\Http\Resources\UserRestaurantProductWithOffersResource;
use Modules\User\Services\UserRestaurantProductsWithOffersService;

final class UserRestaurantProductsWithOffersController
{
    public function __construct(
        private UserRestaurantProductsWithOffersService $service,
    ) {}

    public function __invoke(RestaurantProductsWithOffersRequest $request): JsonResponse
    {
        $products = $this->service->paginateProductsWithActiveOffers(
            restaurantId: $request->getRestaurantId(),
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

        $response = UserRestaurantProductWithOffersResource::collection($products)->response();
        $response->setEncodingOptions($response->getEncodingOptions() | JSON_PRESERVE_ZERO_FRACTION);

        return $response;
    }
}
