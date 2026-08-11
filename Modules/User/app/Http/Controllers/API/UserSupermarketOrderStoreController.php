<?php

declare(strict_types=1);

namespace Modules\User\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Modules\Supermarket\Http\Resources\SmOrderResource;
use Modules\User\Http\Requests\UserSupermarketOrderStoreRequest;
use Modules\User\Services\UserSupermarketCheckoutPipelineService;

final class UserSupermarketOrderStoreController
{
    public function __construct(
        private readonly UserSupermarketCheckoutPipelineService $checkout,
    ) {}

    public function __invoke(UserSupermarketOrderStoreRequest $request, int $cartId): JsonResponse
    {
        $order = $this->checkout->place(
            userId: (int) $request->user()->id,
            cartId: $cartId,
            fulfillmentType: (string) $request->string('fulfillmentType'),
            receiveMode: (string) $request->string('receiveMode'),
            scheduledAt: $request->input('scheduledAt'),
            couponCode: $request->input('couponCode'),
            note: $request->input('note'),
            addressId: $request->integer('addressId') ?: null,
        );

        return response()->json([
            'data' => SmOrderResource::make($order),
        ], 201);
    }
}
