<?php

declare(strict_types=1);

namespace Modules\User\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Modules\User\Http\Requests\UserSupermarketShoppingListItemStoreRequest;
use Modules\User\Services\UserSupermarketShoppingListService;

final class UserSupermarketShoppingListItemStoreController
{
    public function __construct(
        private readonly UserSupermarketShoppingListService $shoppingLists,
    ) {}

    public function __invoke(UserSupermarketShoppingListItemStoreRequest $request, int $shoppingList): JsonResponse
    {
        $validated = $request->validated();

        return response()->json([
            'data' => $this->shoppingLists->storeItem(
                userId: (int) $request->user()->id,
                listId: $shoppingList,
                masterProductId: (int) $validated['masterProductId'],
                quantity: (float) $validated['quantity'],
                unit: $validated['unit'] ?? null,
                sortOrder: (int) $validated['sortOrder'],
                isIncluded: (bool) $validated['isIncluded'],
            ),
        ], 201);
    }
}
