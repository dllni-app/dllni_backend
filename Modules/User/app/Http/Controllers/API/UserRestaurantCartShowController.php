<?php

declare(strict_types=1);

namespace Modules\User\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Modules\User\Services\UserRestaurantCartService;

final class UserRestaurantCartShowController
{
    public function __construct(
        private readonly UserRestaurantCartService $carts,
    ) {}

    public function __invoke(): JsonResponse
    {
        return response()->json([
            'data' => $this->carts->show(
                userId: (int) auth()->id(),
                merchantId: request()->integer('merchantId') ?: null,
            ),
        ]);
    }
}
