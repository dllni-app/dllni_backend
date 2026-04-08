<?php

declare(strict_types=1);

namespace Modules\User\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Modules\User\Http\Requests\UserRestaurantCartItemStoreRequest;
use Modules\User\Services\UserRestaurantCartService;

final class UserRestaurantCartItemStoreController
{
    public function __construct(
        private readonly UserRestaurantCartService $carts,
    ) {}

    public function __invoke(UserRestaurantCartItemStoreRequest $request): JsonResponse
    {
        return response()->json([
            'data' => $this->carts->addItem(
                userId: (int) $request->user()->id,
                merchantId: (int) $request->integer('merchantId'),
                productId: (int) $request->integer('productId'),
                quantity: (int) $request->integer('quantity'),
                modifierIds: $request->input('modifierIds', []),
                substituteProductId: $request->input('substituteProductId'),
                note: $request->input('note'),
            ),
        ], 201);
    }
}
