<?php

declare(strict_types=1);

namespace Modules\User\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Modules\User\Http\Requests\UserSupermarketShoppingListAddToCartRequest;
use Modules\User\Services\UserSupermarketShoppingListService;

final class UserSupermarketShoppingListAddToCartController
{
    public function __construct(
        private readonly UserSupermarketShoppingListService $shoppingLists,
    ) {}

    public function __invoke(UserSupermarketShoppingListAddToCartRequest $request, int $shoppingList): JsonResponse
    {
        $validated = $request->validated();

        return response()->json([
            'data' => $this->shoppingLists->addListToCart(
                userId: (int) $request->user()->id,
                listId: $shoppingList,
                storeId: (int) $validated['storeId'],
            ),
        ], 201);
    }
}
