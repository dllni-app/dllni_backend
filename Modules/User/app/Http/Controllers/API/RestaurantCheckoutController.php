<?php

declare(strict_types=1);

namespace Modules\User\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Modules\Resturants\Http\Resources\OrderResource;
use Modules\User\Http\Requests\RestaurantCheckoutRequest;
use Modules\User\Services\RestaurantCheckoutService;

final class RestaurantCheckoutController
{
    public function __construct(
        private RestaurantCheckoutService $service,
    ) {}

    public function __invoke(RestaurantCheckoutRequest $request): JsonResponse
    {
        $order = $this->service->checkout(
            userId: (int) $request->user()->id,
            restaurantId: (int) $request->validated('restaurantId'),
            orderType: (string) $request->validated('orderType'),
            pickupMode: $request->validated('pickupMode'),
            pickupScheduledFor: $request->validated('pickupScheduledFor'),
            promoCode: $request->validated('promoCode'),
            specialInstructions: $request->validated('specialInstructions'),
        );

        $order->load(['restaurant', 'orderItems.product', 'promoCode']);

        return response()->json([
            'message' => 'Order created.',
            'order' => OrderResource::make($order),
        ], 201);
    }
}
