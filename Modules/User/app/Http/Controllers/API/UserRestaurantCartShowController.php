<?php

declare(strict_types=1);

namespace Modules\User\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\User\Services\UserRestaurantCartService;
use Modules\User\Support\UserRestaurantCartPayload;

final class UserRestaurantCartShowController
{
    public function __construct(
        private readonly UserRestaurantCartService $carts,
    ) {}

    public function __invoke(Request $request, ?int $cartId = null): JsonResponse
    {
        $userId = (int) $request->user()->id;

        $data = $cartId === null
            ? UserRestaurantCartPayload::normalizeMany($this->carts->list($userId))
            : UserRestaurantCartPayload::normalize($this->carts->show($userId, $cartId));

        return response()->json(['data' => $data]);
    }
}
