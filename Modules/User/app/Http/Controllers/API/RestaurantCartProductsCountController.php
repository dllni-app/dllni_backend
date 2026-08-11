<?php

declare(strict_types=1);

namespace Modules\User\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Resturants\Models\CartItem;

final class RestaurantCartProductsCountController
{
    public function __invoke(Request $request): JsonResponse
    {
        $userId = (int) $request->user()->id;

        // The cart badge represents the number of cart line items/products,
        // not the sum of their quantities. For example, 2 burgers + 3 drinks
        // should show 2 items in the badge, not 5.
        $productsCount = CartItem::query()
            ->whereHas('cart', fn ($query) => $query->where('user_id', $userId))
            ->count();

        return response()->json([
            'productsCount' => $productsCount,
        ]);
    }
}
