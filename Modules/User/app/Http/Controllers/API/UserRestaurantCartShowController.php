<?php

declare(strict_types=1);

namespace Modules\User\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\User\Services\UserRestaurantCartService;

final class UserRestaurantCartShowController
{
    public function __construct(
        private readonly UserRestaurantCartService $carts,
    ) {}

    public function __invoke(Request $request, ?int $cartId = null): JsonResponse
    {
        $userId = (int) $request->user()->id;

        return response()->json([
            'data' => $cartId === null
                ? $this->carts->list($userId)
                : $this->carts->show($userId, $cartId),
        ]);
    }
}
