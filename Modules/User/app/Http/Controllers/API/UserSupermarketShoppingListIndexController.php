<?php

declare(strict_types=1);

namespace Modules\User\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Modules\User\Services\UserSupermarketShoppingListService;

final class UserSupermarketShoppingListIndexController
{
    public function __construct(
        private readonly UserSupermarketShoppingListService $shoppingLists,
    ) {}

    public function __invoke(): JsonResponse
    {
        return response()->json([
            'data' => $this->shoppingLists->index((int) auth()->id()),
        ]);
    }
}
