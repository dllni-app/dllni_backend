<?php

declare(strict_types=1);

namespace Modules\User\Http\Controllers\API;

use Illuminate\Http\Response;
use Modules\User\Services\UserSupermarketShoppingListService;

final class UserSupermarketShoppingListItemDestroyController
{
    public function __construct(
        private readonly UserSupermarketShoppingListService $shoppingLists,
    ) {}

    public function __invoke(int $shoppingList, int $item): Response
    {
        $this->shoppingLists->destroyItem(
            userId: (int) auth()->id(),
            listId: $shoppingList,
            itemId: $item,
        );

        return response()->noContent();
    }
}
