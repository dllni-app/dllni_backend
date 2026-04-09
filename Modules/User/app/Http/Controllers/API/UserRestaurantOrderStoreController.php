<?php

declare(strict_types=1);

namespace Modules\User\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Modules\User\Http\Requests\UserRestaurantOrderStoreRequest;
use Modules\User\Services\UserOrderHubService;
use Modules\User\Services\UserRestaurantCheckoutPipelineService;

final class UserRestaurantOrderStoreController
{
    public function __construct(
        private readonly UserRestaurantCheckoutPipelineService $checkout,
        private readonly UserOrderHubService $orders,
    ) {}

    public function __invoke(UserRestaurantOrderStoreRequest $request): JsonResponse
    {
        $order = $this->checkout->place(
            userId: (int) $request->user()->id,
            fulfillmentType: (string) $request->string('fulfillmentType'),
            receiveMode: (string) $request->string('receiveMode'),
            scheduledAt: $request->input('scheduledAt'),
            couponCode: $request->input('couponCode'),
            note: $request->input('note'),
        );

        return response()->json([
            'data' => $this->orders->show((int) $request->user()->id, 'restaurant', (int) $order->id),
        ], 201);
    }
}
