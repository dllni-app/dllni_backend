<?php

declare(strict_types=1);

namespace Modules\User\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Modules\User\Http\Requests\UserRestaurantCartItemUpdateRequest;
use Modules\User\Services\UserRestaurantCartService;

final class UserRestaurantCartItemUpdateController
{
    public function __construct(
        private readonly UserRestaurantCartService $carts,
    ) {}

    public function __invoke(UserRestaurantCartItemUpdateRequest $request, int $itemId): JsonResponse
    {
        return response()->json([
            'data' => $this->carts->updateItem(
                userId: (int) $request->user()->id,
                itemId: $itemId,
                quantity: (int) $request->integer('quantity'),
                modifierIds: $request->input('modifierIds', []),
                substituteProductId: $request->input('substituteProductId'),
                note: $request->input('note'),
            ),
        ]);
    }
}
