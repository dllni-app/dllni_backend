<?php

declare(strict_types=1);

namespace Modules\User\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Supermarket\Http\Resources\SmStoreResource;
use Modules\Supermarket\Models\SmStore;
use Modules\User\Services\UserFavoriteService;

final class UserSupermarketStoreFavoriteStoreController
{
    public function __invoke(Request $request, SmStore $store, UserFavoriteService $favoriteService): JsonResponse
    {
        if (! $store->is_active) {
            abort(422, 'This store is not available.');
        }

        $favorite = $favoriteService->addSupermarketStoreFavorite($request->user(), $store);

        $store->load('owner', 'highestDiscountOffer');
        $store->setAttribute('isFavoritedByUser', true);

        return response()->json([
            'store' => SmStoreResource::make($store),
        ], $favorite->wasRecentlyCreated ? 201 : 200);
    }
}
