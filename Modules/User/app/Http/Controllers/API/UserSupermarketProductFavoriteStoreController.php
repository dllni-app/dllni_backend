<?php

declare(strict_types=1);

namespace Modules\User\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Supermarket\Http\Resources\SmProductResource;
use Modules\Supermarket\Models\SmProduct;
use Modules\User\Services\UserFavoriteService;

final class UserSupermarketProductFavoriteStoreController
{
    public function __invoke(Request $request, SmProduct $product, UserFavoriteService $favoriteService): JsonResponse
    {
        $product->loadMissing('store');

        if (! $product->is_available || ! $product->store?->is_active) {
            abort(422, 'This product is not available.');
        }

        $favorite = $favoriteService->addSupermarketProductFavorite($request->user(), $product);

        $product->load(['store', 'category', 'media', 'offerProducts.offer']);
        $product->setAttribute('isFavoritedByUser', true);

        return response()->json([
            'product' => SmProductResource::make($product),
        ], $favorite->wasRecentlyCreated ? 201 : 200);
    }
}
