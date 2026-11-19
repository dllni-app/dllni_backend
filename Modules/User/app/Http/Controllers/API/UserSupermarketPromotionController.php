<?php

declare(strict_types=1);

namespace Modules\User\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\User\Services\UserSupermarketCartService;

final class UserSupermarketPromotionController
{
    public function add(Request $request, int $offerId, UserSupermarketCartService $carts): JsonResponse
    {
        return response()->json(['data' => $carts->addOffer((int) $request->user()->id, $offerId, $this->data($request, false))], 201);
    }

    public function claim(Request $request, int $cartId, int $offerId, UserSupermarketCartService $carts): JsonResponse
    {
        return response()->json(['data' => $carts->claimReward((int) $request->user()->id, $cartId, $offerId, $this->data($request, true))]);
    }

    private function data(Request $request, bool $claim): array
    {
        return $request->validate([
            'productId' => ['required', 'integer', 'min:1'], 'quantity' => ['required', 'integer', 'min:1', 'max:200'],
            'ruleVersion' => [$claim ? 'sometimes' : 'required', 'integer', 'min:1'],
            'expectedRevision' => [$claim ? 'required' : 'sometimes', 'integer', 'min:0'],
            'modifierIds' => ['sometimes', 'array', 'max:30'], 'modifierIds.*' => ['integer', 'distinct', 'min:1'],
            'note' => ['nullable', 'string', 'max:1000'], 'rewardProductId' => ['nullable', 'integer', 'min:1'],
            'rewardModifierIds' => ['sometimes', 'array', 'max:30'], 'rewardModifierIds.*' => ['integer', 'distinct', 'min:1'], 'rewardNote' => ['nullable', 'string', 'max:1000'],
        ]);
    }
}
