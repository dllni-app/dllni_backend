<?php

declare(strict_types=1);

namespace Modules\User\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Modules\User\Http\Requests\UserSupermarketShoppingListUpdateRequest;
use Modules\User\Services\UserSupermarketShoppingListService;

final class UserSupermarketShoppingListUpdateController
{
    public function __construct(
        private readonly UserSupermarketShoppingListService $shoppingLists,
    ) {}

    public function __invoke(UserSupermarketShoppingListUpdateRequest $request, int $shoppingList): JsonResponse
    {
        $validated = $request->validated();

        return response()->json([
            'data' => $this->shoppingLists->updateList(
                userId: (int) $request->user()->id,
                listId: $shoppingList,
                validated: $validated,
            ),
        ]);
    }
}
