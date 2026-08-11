<?php

declare(strict_types=1);

namespace Modules\User\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Modules\Resturants\Http\Resources\OrderResource;
use Modules\User\Http\Requests\UserRestaurantOrderStoreRequest;
use Modules\User\Services\UserRestaurantCheckoutPipelineService;

final class UserRestaurantOrderStoreController
{
    public function __construct(
        private readonly UserRestaurantCheckoutPipelineService $checkout,
    ) {}

    public function __invoke(UserRestaurantOrderStoreRequest $request, int $cartId): JsonResponse
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
            'data' => OrderResource::make($order),
        ], 201);
    }
}
