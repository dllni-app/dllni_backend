<?php

declare(strict_types=1);

namespace Modules\User\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\User\Services\UserRestaurantCartService;
use Modules\User\Support\UserRestaurantCartPayload;

final class UserRestaurantOfferAddController
{
    public function __construct(private readonly UserRestaurantCartService $carts) {}

    public function __invoke(Request $request, int $offerId): JsonResponse
    {
        $data = $request->validate([
            'productId' => ['required', 'integer', 'exists:products,id'],
            'quantity' => ['required', 'integer', 'min:1', 'max:50'],
            'ruleVersion' => ['required', 'integer', 'min:1'],
            'modifierIds' => ['sometimes', 'array', 'max:30'],
            'modifierIds.*' => ['integer', 'exists:modifiers,id'],
            'note' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'rewardProductId' => ['sometimes', 'nullable', 'integer', 'exists:products,id'],
            'rewardModifierIds' => ['sometimes', 'array', 'max:30'],
            'rewardModifierIds.*' => ['integer', 'exists:modifiers,id'],
            'rewardNote' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ]);

        $cart = $this->carts->addOffer(
            userId: (int) $request->user()->id,
            offerId: $offerId,
            productId: (int) $data['productId'],
            quantity: (int) $data['quantity'],
            ruleVersion: (int) $data['ruleVersion'],
            modifierIds: $data['modifierIds'] ?? [],
            note: $data['note'] ?? null,
            rewardProductId: isset($data['rewardProductId']) ? (int) $data['rewardProductId'] : null,
            rewardModifierIds: $data['rewardModifierIds'] ?? [],
            rewardNote: $data['rewardNote'] ?? null,
        );

        return response()->json(['data' => UserRestaurantCartPayload::normalize($cart)], 201);
    }
}
