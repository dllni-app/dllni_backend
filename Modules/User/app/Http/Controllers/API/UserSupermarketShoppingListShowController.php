<?php

declare(strict_types=1);

namespace Modules\User\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Modules\User\Services\UserSupermarketShoppingListService;

final class UserSupermarketShoppingListShowController
{
    public function __construct(
        private readonly UserSupermarketShoppingListService $shoppingLists,
    ) {}

    public function __invoke(int $shoppingList): JsonResponse
    {
        return response()->json([
            'data' => $this->shoppingLists->show(
                userId: (int) auth()->id(),
                listId: $shoppingList,
            ),
        ]);
    }
}
