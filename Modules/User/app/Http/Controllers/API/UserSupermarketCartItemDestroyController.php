<?php

declare(strict_types=1);

namespace Modules\User\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Modules\User\Services\UserSupermarketCartService;

final class UserSupermarketCartItemDestroyController
{
    public function __construct(
        private readonly UserSupermarketCartService $carts,
    ) {}

    public function __invoke(int $itemId): JsonResponse
    {
        return response()->json([
            'data' => $this->carts->deleteItem((int) auth()->id(), $itemId),
        ]);
    }
}
