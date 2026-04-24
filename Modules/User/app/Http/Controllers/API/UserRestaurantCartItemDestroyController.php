<?php

declare(strict_types=1);

namespace Modules\User\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\User\Services\UserRestaurantCartService;

final class UserRestaurantCartItemDestroyController
{
    public function __construct(
        private readonly UserRestaurantCartService $carts,
    ) {}

    public function __invoke(Request $request, int $itemId): JsonResponse
    {
        return response()->json([
            'data' => $this->carts->deleteItem((int) $request->user()->id, $itemId),
        ]);
    }
}
