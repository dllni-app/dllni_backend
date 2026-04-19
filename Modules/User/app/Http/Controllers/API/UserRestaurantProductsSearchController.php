<?php

declare(strict_types=1);

namespace Modules\User\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Modules\User\Http\Requests\UserRestaurantProductsSearchRequest;
use Modules\User\Http\Resources\UserRestaurantProductWithOffersResource;
use Modules\User\Services\UserRestaurantProductsSearchService;

final class UserRestaurantProductsSearchController
{
    public function __construct(
        private UserRestaurantProductsSearchService $service,
    ) {}

    public function __invoke(UserRestaurantProductsSearchRequest $request): JsonResponse
    {
        $products = $this->service->search($request);

        $user = $request->user('sanctum');
        if ($user !== null) {
            $products->getCollection()->each(function ($product) use ($user): void {
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
