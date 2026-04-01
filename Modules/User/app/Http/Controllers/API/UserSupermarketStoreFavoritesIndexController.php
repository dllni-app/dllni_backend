<?php

declare(strict_types=1);

namespace Modules\User\Http\Controllers\API;

use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Modules\Supermarket\Http\Resources\SmStoreResource;
use Modules\User\Http\Requests\UserFavoritesIndexRequest;
use Modules\User\Services\UserFavoriteService;

final class UserSupermarketStoreFavoritesIndexController
{
    public function __invoke(UserFavoritesIndexRequest $request, UserFavoriteService $favoriteService): AnonymousResourceCollection
    {
        $stores = $favoriteService->paginateFavoriteSupermarketStores(
            $request->user(),
            $request->integer('perPage', 20),
        );

        return SmStoreResource::collection($stores);
    }
}
