<?php

declare(strict_types=1);

namespace Modules\User\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\User\Services\UserRestaurantCartService;
use Modules\User\Support\UserRestaurantCartPayload;

final class UserRestaurantCartItemDestroyController
{
    public function __construct(
        private readonly UserRestaurantCartService $carts,
    ) {}

    public function __invoke(Request $request, int $cartId, int $itemId): JsonResponse
    {
        $cart = $this->carts->deleteItem((int) $request->user()->id, $cartId, $itemId);

        return response()->json([
            'data' => UserRestaurantCartPayload::normalize($cart),
        ]);
    }
}
