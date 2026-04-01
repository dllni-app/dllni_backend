<?php

declare(strict_types=1);

namespace Modules\User\Http\Controllers\API;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Modules\Supermarket\Models\SmStore;
use Modules\User\Services\UserFavoriteService;

final class UserSupermarketStoreFavoriteDestroyController
{
    public function __invoke(Request $request, SmStore $store, UserFavoriteService $favoriteService): Response
    {
        $favoriteService->removeSupermarketStoreFavorite($request->user(), $store);

        return response()->noContent();
    }
}
