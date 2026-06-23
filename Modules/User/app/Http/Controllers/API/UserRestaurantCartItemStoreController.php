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
        $result = $this->carts->addItem(
            userId: (int) $request->user()->id,
            productId: (int) $request->integer('productId'),
            quantity: (int) $request->integer('quantity'),
            modifierIds: $request->input('modifierIds', []),
            substituteProductId: $request->input('substituteProductId'),
            note: $request->input('specialInstructions') ?? $request->input('note'),
        );

        return response()->json([
            'message' => $result['operation'] === 'updated'
                ? 'Item updated in cart.'
                : 'Item added to cart.',
            'cartId' => $result['cartId'],
            'itemId' => $result['itemId'],
            'quantity' => $result['quantity'],
            'operation' => $result['operation'],
            'cartProductsCount' => $result['cartProductsCount'],
        ], $result['operation'] === 'updated' ? 200 : 201);
    }
}
