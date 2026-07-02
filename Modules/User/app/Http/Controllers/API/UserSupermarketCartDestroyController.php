<?php

declare(strict_types=1);

namespace Modules\User\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\User\Services\UserSupermarketCartService;

final class UserSupermarketCartDestroyController
{
    public function __construct(
        private readonly UserSupermarketCartService $carts,
    ) {}

    public function __invoke(Request $request, int $cartId): JsonResponse
    {
        return response()->json([
            'data' => $this->carts->deleteCart((int) $request->user()->id, $cartId),
        ]);
    }
}
