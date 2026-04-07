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

        $productsCount = (int) CartItem::query()
            ->whereHas('cart', fn ($query) => $query->where('user_id', $userId))
            ->sum('quantity');

        return response()->json([
            'productsCount' => $productsCount,
        ]);
    }
}
