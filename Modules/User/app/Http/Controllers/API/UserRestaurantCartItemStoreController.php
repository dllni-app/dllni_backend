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
            quantityMode: $request->input('quantityMode', 'increment'),
        );

        return response()->json([
            'message' => $result['message'],
            'cartId' => $result['cartId'],
            'itemId' => $result['itemId'],
            'quantity' => $result['quantity'],
            'cartProductsCount' => $result['cartProductsCount'],
            'operation' => $result['operation'],
        ], $result['operation'] === 'created' ? 201 : 200);
    }
}
