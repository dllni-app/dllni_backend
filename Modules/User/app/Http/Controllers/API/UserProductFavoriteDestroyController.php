<?php

declare(strict_types=1);

namespace Modules\User\Http\Controllers\API;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Modules\Resturants\Models\Product;
use Modules\User\Services\UserFavoriteService;

final class UserProductFavoriteDestroyController
{
    public function __invoke(Request $request, Product $product, UserFavoriteService $favoriteService): Response
    {
        $favoriteService->removeProductFavorite($request->user(), $product);

        return response()->noContent();
    }
}
