<?php

declare(strict_types=1);

namespace Modules\User\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Modules\User\Http\Requests\UserSupermarketShoppingListStoreRequest;
use Modules\User\Services\UserSupermarketShoppingListService;

final class UserSupermarketShoppingListStoreController
{
    public function __construct(
        private readonly UserSupermarketShoppingListService $shoppingLists,
    ) {}

    public function __invoke(UserSupermarketShoppingListStoreRequest $request): JsonResponse
    {
        $validated = $request->validated();

        return response()->json([
            'data' => $this->shoppingLists->store(
                userId: (int) $request->user()->id,
                name: (string) $validated['name'],
                description: $validated['description'] ?? null,
                isActive: (bool) $validated['isActive'],
            ),
        ], 201);
    }
}
