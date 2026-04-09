<?php

declare(strict_types=1);

namespace Modules\User\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Modules\Resturants\Http\Resources\OrderResource;
use Modules\User\Http\Requests\UserRestaurantCheckoutRequest;
use Modules\User\Services\UserRestaurantCheckoutPipelineService;

final class UserRestaurantCheckoutController
{
    public function __construct(
        private readonly UserRestaurantCheckoutPipelineService $checkout,
    ) {}

    public function __invoke(UserRestaurantCheckoutRequest $request): JsonResponse
    {
        $order = $this->checkout->place(
            userId: (int) $request->user()->id,
            fulfillmentType: (string) $request->string('orderType'),
            receiveMode: 'immediate',
            scheduledAt: null,
            couponCode: $request->input('promoCode'),
            note: $request->input('specialInstructions'),
        );

        return response()->json([
            'message' => 'Order created successfully.',
            'order' => OrderResource::make($order),
        ], 201);
    }
}
