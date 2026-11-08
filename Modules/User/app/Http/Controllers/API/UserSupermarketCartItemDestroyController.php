<?php

declare(strict_types=1);

namespace Modules\User\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\User\Services\UserSupermarketCartService;

final class UserSupermarketCartItemDestroyController
{
    public function __construct(
        private readonly UserSupermarketCartService $carts,
    ) {}

    public function __invoke(Request $request, int $cartId, int $itemId): JsonResponse
    {
        $request->validate(['expectedRevision' => ['sometimes', 'integer', 'min:0']]);

        return response()->json([
            'data' => $this->carts->deleteItem(
                (int) $request->user()->id, $cartId, $itemId,
                $request->has('expectedRevision') ? $request->integer('expectedRevision') : null,
            ),
        ]);
    }
}
