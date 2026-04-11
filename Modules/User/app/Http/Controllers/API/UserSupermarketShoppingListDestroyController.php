<?php

declare(strict_types=1);

namespace Modules\User\Http\Controllers\API;

use Illuminate\Http\Response;
use Modules\User\Services\UserSupermarketShoppingListService;

final class UserSupermarketShoppingListDestroyController
{
    public function __construct(
        private readonly UserSupermarketShoppingListService $shoppingLists,
    ) {}

    public function __invoke(int $shoppingList): Response
    {
        $this->shoppingLists->destroy(
            userId: (int) auth()->id(),
            listId: $shoppingList,
        );

        return response()->noContent();
    }
}
