<?php

declare(strict_types=1);

namespace Modules\User\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Modules\User\Http\Requests\UserSupermarketCartItemUpdateRequest;
use Modules\User\Services\UserSupermarketCartService;

final class UserSupermarketCartItemUpdateController
{
    public function __construct(
        private readonly UserSupermarketCartService $carts,
    ) {}

    public function __invoke(UserSupermarketCartItemUpdateRequest $request, int $itemId): JsonResponse
    {
        return response()->json([
            'data' => $this->carts->updateItem(
                userId: (int) $request->user()->id,
                itemId: $itemId,
                quantity: (int) $request->integer('quantity'),
            ),
        ]);
    }
}
