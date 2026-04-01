<?php

declare(strict_types=1);

namespace Modules\User\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Resturants\Http\Resources\RestaurantResource;
use Modules\Resturants\Models\Restaurant;
use Modules\User\Services\UserFavoriteService;

final class UserRestaurantFavoriteStoreController
{
    public function __invoke(Request $request, Restaurant $restaurant, UserFavoriteService $favoriteService): JsonResponse
    {
        if (! $restaurant->is_active) {
            abort(422, 'This restaurant is not available.');
        }

        $favorite = $favoriteService->addRestaurantFavorite($request->user(), $restaurant);

        $restaurant->load(['media', 'cuisineTypes', 'primaryActiveOffer']);

        return response()->json([
            'restaurant' => RestaurantResource::make($restaurant),
        ], $favorite->wasRecentlyCreated ? 201 : 200);
    }
}
