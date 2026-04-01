<?php

declare(strict_types=1);

namespace Modules\User\Http\Controllers\API;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Modules\Resturants\Models\Restaurant;
use Modules\User\Services\UserFavoriteService;

final class UserRestaurantFavoriteDestroyController
{
    public function __invoke(Request $request, Restaurant $restaurant, UserFavoriteService $favoriteService): Response
    {
        $favoriteService->removeRestaurantFavorite($request->user(), $restaurant);

        return response()->noContent();
    }
}
