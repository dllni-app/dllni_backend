<?php

declare(strict_types=1);

namespace Modules\User\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Modules\User\Http\Requests\UserSupermarketCartItemStoreRequest;
use Modules\User\Services\UserSupermarketCartService;

final class UserSupermarketCartItemStoreController
{
    public function __construct(
        private readonly UserSupermarketCartService $carts,
    ) {}

    public function __invoke(UserSupermarketCartItemStoreRequest $request): JsonResponse
    {
        return response()->json([
            'data' => $this->carts->addItem(
                userId: (int) $request->user()->id,
                merchantId: (int) $request->integer('merchantId'),
                productId: (int) $request->integer('productId'),
                quantity: (int) $request->integer('quantity'),
            ),
        ], 201);
    }
}
