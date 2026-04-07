<?php

declare(strict_types=1);

namespace Modules\User\Http\Controllers\API;

use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Modules\Supermarket\Http\Resources\SmProductResource;
use Modules\User\Http\Requests\UserFavoritesIndexRequest;
use Modules\User\Services\UserFavoriteService;

final class UserSupermarketProductFavoritesIndexController
{
    public function __invoke(UserFavoritesIndexRequest $request, UserFavoriteService $favoriteService): AnonymousResourceCollection
    {
        $products = $favoriteService->paginateFavoriteSupermarketProducts(
            $request->user(),
            $request->integer('perPage', 20),
        );

        return SmProductResource::collection($products);
    }
}
