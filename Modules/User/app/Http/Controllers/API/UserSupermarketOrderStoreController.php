<?php

declare(strict_types=1);

namespace Modules\User\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Modules\User\Http\Requests\UserSupermarketOrderStoreRequest;
use Modules\User\Services\UserOrderHubService;
use Modules\User\Services\UserSupermarketCheckoutPipelineService;

final class UserSupermarketOrderStoreController
{
    public function __construct(
        private readonly UserSupermarketCheckoutPipelineService $checkout,
        private readonly UserOrderHubService $orders,
    ) {}

    public function __invoke(UserSupermarketOrderStoreRequest $request): JsonResponse
    {
        $order = $this->checkout->place(
            userId: (int) $request->user()->id,
            merchantId: (int) $request->integer('merchantId'),
            receiveMode: (string) $request->string('receiveMode'),
            scheduledAt: $request->input('scheduledAt'),
            couponCode: $request->input('couponCode'),
            note: $request->input('note'),
        );

        return response()->json([
            'data' => $this->orders->show((int) $request->user()->id, 'supermarket', (int) $order->id),
        ], 201);
    }
}
