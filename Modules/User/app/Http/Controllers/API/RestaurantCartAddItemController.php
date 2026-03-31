<?php

declare(strict_types=1);

namespace Modules\User\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Modules\User\Http\Requests\AddToRestaurantCartRequest;
use Modules\User\Services\RestaurantCartService;

final class RestaurantCartAddItemController
{
    public function __construct(
        private RestaurantCartService $service,
    ) {}

    public function __invoke(AddToRestaurantCartRequest $request): JsonResponse
    {
        $userId = (int) $request->user()->id;

        $result = $this->service->addProductToCart(
            userId: $userId,
            productId: (int) $request->validated('productId'),
            quantity: (int) $request->integer('quantity', 1),
            modifierIds: $request->validated('modifierIds', []),
            substituteProductId: $request->validated('substituteProductId'),
            specialInstructions: $request->validated('specialInstructions'),
        );

        return response()->json([
            'message' => 'Added to cart.',
            'cartId' => $result['cart']->id,
            'itemId' => $result['item']->id,
        ], 201);
    }
}
