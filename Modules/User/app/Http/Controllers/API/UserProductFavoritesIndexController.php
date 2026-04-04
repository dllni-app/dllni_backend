<?php

declare(strict_types=1);

namespace Modules\User\Http\Controllers\API;

use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Modules\User\Http\Requests\UserFavoritesIndexRequest;
use Modules\User\Http\Resources\UserRestaurantProductWithOffersResource;
use Modules\User\Services\UserFavoriteService;

final class UserProductFavoritesIndexController
{
    public function __invoke(UserFavoritesIndexRequest $request, UserFavoriteService $favoriteService): AnonymousResourceCollection
    {
        $products = $favoriteService->paginateFavoriteProducts(
            $request->user(),
            $request->integer('perPage', 20),
        );

        return UserRestaurantProductWithOffersResource::collection($products);
    }
}
