<?php

declare(strict_types=1);

namespace Modules\User\Http\Controllers\API;

use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Modules\Resturants\Http\Resources\RestaurantResource;
use Modules\User\Http\Requests\UserFavoritesIndexRequest;
use Modules\User\Services\UserFavoriteService;

final class UserRestaurantFavoritesIndexController
{
    public function __invoke(UserFavoritesIndexRequest $request, UserFavoriteService $favoriteService): AnonymousResourceCollection
    {
        $restaurants = $favoriteService->paginateFavoriteRestaurants(
            $request->user(),
            $request->integer('perPage', 20),
        );

        return RestaurantResource::collection($restaurants);
    }
}
