<?php

declare(strict_types=1);

namespace Modules\User\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Modules\User\Http\Requests\UserSupermarketOrderStoreRequest;
use Modules\User\Services\UserSupermarketCheckoutPipelineService;

final class UserSupermarketOrderStoreController
{
    public function __construct(
        private readonly UserSupermarketCheckoutPipelineService $checkout,
    ) {}

    public function __invoke(UserSupermarketOrderStoreRequest $request): JsonResponse
    {
        return response()->json([
            'data' => $this->checkout->place(
                userId: (int) $request->user()->id,
                fulfillmentType: (string) $request->string('fulfillmentType'),
                receiveMode: (string) $request->string('receiveMode'),
                scheduledAt: $request->input('scheduledAt'),
                couponCode: $request->input('couponCode'),
                note: $request->input('note'),
                merchantCoupons: $request->input('merchantCoupons'),
            ),
        ], 201);
    }
}
