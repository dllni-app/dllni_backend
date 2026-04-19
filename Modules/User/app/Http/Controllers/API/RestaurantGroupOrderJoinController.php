<?php

declare(strict_types=1);

namespace Modules\User\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Modules\User\Events\RestaurantGroupOrderUpdated;
use Modules\User\Http\Requests\RestaurantGroupOrderJoinRequest;
use Modules\User\Services\RestaurantGroupOrderService;

final class RestaurantGroupOrderJoinController
{
    public function __construct(
        private readonly RestaurantGroupOrderService $service,
    ) {}

    public function __invoke(RestaurantGroupOrderJoinRequest $request): JsonResponse
    {
        $userId = (int) $request->user()->id;
        $groupOrder = $this->service->joinByToken(
            shareToken: (string) $request->string('shareToken'),
            userId: $userId,
        );

        $payload = $this->service->publicPayload($groupOrder, $userId);
        RestaurantGroupOrderUpdated::dispatch($groupOrder, $payload['groupOrder']);

        return response()->json([
            'message' => 'Joined group order.',
            'data' => $payload,
        ]);
    }
}
