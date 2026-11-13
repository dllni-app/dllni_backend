<?php

declare(strict_types=1);

namespace Modules\User\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Modules\User\Http\Requests\UserRestaurantPromotionClaimRequest;
use Modules\User\Services\UserRestaurantCartService;
use Modules\User\Support\UserRestaurantCartPayload;

final class UserRestaurantPromotionClaimController
{
    public function __construct(private readonly UserRestaurantCartService $carts) {}

    public function __invoke(UserRestaurantPromotionClaimRequest $request, int $cartId, int $offerId): JsonResponse
    {
        $cart = $this->carts->claimReward(
            userId: (int) $request->user()->id,
            cartId: $cartId,
            offerId: $offerId,
            productId: $request->integer('productId'),
            quantity: $request->integer('quantity'),
            modifierIds: $request->validated('modifierIds', []),
            substituteProductId: $request->filled('substituteProductId') ? $request->integer('substituteProductId') : null,
            note: $request->validated('note'),
            expectedRevision: $request->integer('expectedRevision'),
            convertItemId: $request->filled('convertItemId') ? $request->integer('convertItemId') : null,
        );

        return response()->json(['data' => UserRestaurantCartPayload::normalize($cart)]);
    }
}
