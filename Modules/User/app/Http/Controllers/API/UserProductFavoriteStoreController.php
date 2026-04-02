<?php

declare(strict_types=1);

namespace Modules\User\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Resturants\Http\Resources\ProductResource;
use Modules\Resturants\Models\Product;
use Modules\User\Services\UserFavoriteService;

final class UserProductFavoriteStoreController
{
    public function __invoke(Request $request, Product $product, UserFavoriteService $favoriteService): JsonResponse
    {
        if (! $product->is_available || ! $product->restaurant?->is_active) {
            abort(422, 'This product is not available.');
        }

        $favorite = $favoriteService->addProductFavorite($request->user(), $product);

        $product->load([
            'media',
            'category',
            'restaurant.media',
            'restaurant.cuisineTypes',
        ]);

        $product->setAttribute('isFavoritedByUser', true);

        return response()->json([
            'product' => ProductResource::make($product),
        ], $favorite->wasRecentlyCreated ? 201 : 200);
    }
}
