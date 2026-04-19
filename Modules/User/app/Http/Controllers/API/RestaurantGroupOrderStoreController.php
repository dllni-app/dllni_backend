<?php

declare(strict_types=1);

namespace Modules\User\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Modules\User\Events\RestaurantGroupOrderUpdated;
use Modules\User\Http\Requests\RestaurantGroupOrderStoreRequest;
use Modules\User\Services\RestaurantGroupOrderService;

final class RestaurantGroupOrderStoreController
{
    public function __construct(
        private readonly RestaurantGroupOrderService $service,
    ) {}

    public function __invoke(RestaurantGroupOrderStoreRequest $request): JsonResponse
    {
        $userId = (int) $request->user()->id;

        $groupOrder = $this->service->create(
            organizerUserId: $userId,
            restaurantId: (int) $request->integer('restaurantId'),
            name: $request->validated('name'),
            durationMinutes: (int) $request->integer('durationMinutes'),
        );

        $payload = $this->service->publicPayload($groupOrder, $userId);
        RestaurantGroupOrderUpdated::dispatch($groupOrder, $payload['groupOrder']);

        return response()->json([
            'message' => 'Group order created.',
            'data' => $payload,
        ], 201);
    }
}
