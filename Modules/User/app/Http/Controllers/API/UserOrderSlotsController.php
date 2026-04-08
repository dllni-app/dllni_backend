<?php

declare(strict_types=1);

namespace Modules\User\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Modules\User\Http\Requests\UserOrderSlotsRequest;
use Modules\User\Services\UserOrderHubService;

final class UserOrderSlotsController
{
    public function __construct(
        private readonly UserOrderHubService $orders,
    ) {}

    public function __invoke(UserOrderSlotsRequest $request): JsonResponse
    {
        return response()->json([
            'data' => $this->orders->slots(
                section: (string) $request->input('section'),
                merchantId: (int) $request->integer('merchantId'),
                fulfillmentType: $request->input('fulfillmentType'),
                date: (string) $request->string('date'),
            ),
        ]);
    }
}
