<?php

declare(strict_types=1);

namespace Modules\User\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\User\Services\UserSupermarketCartService;

final class UserSupermarketCartShowController
{
    public function __construct(
        private readonly UserSupermarketCartService $carts,
    ) {}

    public function __invoke(Request $request, ?int $cartId = null): JsonResponse
    {
        $userId = (int) $request->user()->id;

        if ($cartId !== null) {
            return response()->json([
                'data' => $this->carts->show($userId, $cartId),
            ]);
        }

        return response()->json([
            'data' => $this->carts->list($userId),
        ]);
    }
}
