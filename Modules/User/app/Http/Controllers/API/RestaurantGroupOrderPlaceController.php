<?php

declare(strict_types=1);

namespace Modules\User\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Modules\Resturants\Models\RestaurantGroupOrder;
use Modules\User\Events\RestaurantGroupOrderUpdated;
use Modules\User\Services\RestaurantGroupOrderService;

final class RestaurantGroupOrderPlaceController
{
    public function __construct(
        private readonly RestaurantGroupOrderService $service,
    ) {}

    public function __invoke(int $groupOrder): JsonResponse
    {
        $model = RestaurantGroupOrder::query()->findOrFail($groupOrder);
        $userId = (int) Auth::id();

        $this->service->placeNow($model, $userId);

        $model->refresh();
        $payload = $this->service->publicPayload($model, $userId);
        RestaurantGroupOrderUpdated::dispatch($model, $payload['groupOrder']);

        return response()->json([
            'message' => 'Group order placed.',
            'data' => $payload,
        ]);
    }
}
