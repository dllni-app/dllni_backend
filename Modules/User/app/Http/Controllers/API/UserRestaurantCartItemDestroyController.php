<?php

declare(strict_types=1);

namespace Modules\User\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Modules\User\Services\UserRestaurantCartService;

final class UserRestaurantCartItemDestroyController
{
    public function __construct(
        private readonly UserRestaurantCartService $carts,
    ) {}

    public function __invoke(int $itemId): JsonResponse
    {
        return response()->json([
            'data' => $this->carts->deleteItem((int) auth()->id(), $itemId),
        ]);
    }
}
