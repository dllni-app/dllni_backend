<?php

declare(strict_types=1);

namespace Modules\User\Http\Controllers\API;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Modules\Supermarket\Models\SmProduct;
use Modules\User\Services\UserFavoriteService;

final class UserSupermarketProductFavoriteDestroyController
{
    public function __invoke(Request $request, SmProduct $product, UserFavoriteService $favoriteService): Response
    {
        $favoriteService->removeSupermarketProductFavorite($request->user(), $product);

        return response()->noContent();
    }
}
