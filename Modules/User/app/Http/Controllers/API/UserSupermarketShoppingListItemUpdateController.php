<?php

declare(strict_types=1);

namespace Modules\User\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Modules\User\Http\Requests\UserSupermarketShoppingListItemUpdateRequest;
use Modules\User\Services\UserSupermarketShoppingListService;

final class UserSupermarketShoppingListItemUpdateController
{
    public function __construct(
        private readonly UserSupermarketShoppingListService $shoppingLists,
    ) {}

    public function __invoke(UserSupermarketShoppingListItemUpdateRequest $request, int $shoppingList, int $item): JsonResponse
    {
        $validated = $request->validated();

        return response()->json([
            'data' => $this->shoppingLists->updateItem(
                userId: (int) $request->user()->id,
                listId: $shoppingList,
                itemId: $item,
                quantity: array_key_exists('quantity', $validated) ? (float) $validated['quantity'] : null,
                sortOrder: array_key_exists('sortOrder', $validated) ? (int) $validated['sortOrder'] : null,
                isIncluded: array_key_exists('isIncluded', $validated) ? (bool) $validated['isIncluded'] : null,
            ),
        ]);
    }
}
