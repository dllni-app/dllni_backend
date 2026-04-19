<?php

declare(strict_types=1);

namespace Modules\User\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Modules\Resturants\Models\RestaurantGroupOrder;
use Modules\User\Services\RestaurantGroupOrderService;

final class RestaurantGroupOrderShowController
{
    public function __construct(
        private readonly RestaurantGroupOrderService $service,
    ) {}

    public function __invoke(int $groupOrder): JsonResponse
    {
        $model = RestaurantGroupOrder::query()->findOrFail($groupOrder);

        return response()->json([
            'data' => $this->service->publicPayload($model, (int) Auth::id()),
        ]);
    }
}
