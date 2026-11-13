<?php

declare(strict_types=1);

namespace Modules\User\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Modules\User\Http\Requests\UserCommerceCheckoutQuoteRequest;
use Modules\User\Services\CommerceCheckoutQuoteService;
use Modules\User\Services\UserRestaurantCheckoutPipelineService;
use Modules\User\Services\UserSupermarketCheckoutPipelineService;

final class UserCommerceCheckoutQuoteStoreController
{
    public function __construct(
        private readonly UserRestaurantCheckoutPipelineService $restaurants,
        private readonly UserSupermarketCheckoutPipelineService $supermarkets,
        private readonly CommerceCheckoutQuoteService $quotes,
    ) {}

    public function __invoke(UserCommerceCheckoutQuoteRequest $request): JsonResponse
    {
        $userId = (int) $request->user()->id;
        $section = (string) $request->string('section');
        $cartId = (int) $request->integer('cartId');
        $selection = [
            'fulfillmentType' => (string) $request->string('fulfillmentType'),
            'receiveMode' => (string) $request->string('receiveMode'),
            'scheduledAt' => $request->input('scheduledAt'),
            'couponCode' => $request->input('couponCode'),
            'note' => $request->input('note'),
            'addressId' => $request->integer('addressId') ?: null,
        ];

        $pipeline = $section === 'restaurant' ? $this->restaurants : $this->supermarkets;
        $preview = $pipeline->preview(
            userId: $userId,
            cartId: $cartId,
            fulfillmentType: $selection['fulfillmentType'],
            receiveMode: $selection['receiveMode'],
            scheduledAt: $selection['scheduledAt'],
            couponCode: $selection['couponCode'],
            note: $selection['note'],
            addressId: $selection['addressId'],
        );

        return response()->json([
            'data' => array_merge($preview, $this->quotes->issue(
                userId: $userId,
                section: $section,
                cartId: $cartId,
                selection: $selection,
                amounts: $preview['amounts'],
                expectedPricingFingerprint: $preview['pricingFingerprint'] ?? null,
            )),
        ], 201);
    }
}