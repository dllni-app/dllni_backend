<?php

declare(strict_types=1);

namespace Modules\User\Http\Controllers\API;

use App\Support\Broadcast\BroadcastAfterResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Modules\Resturants\Models\RestaurantGroupOrder;
use Modules\User\Events\RestaurantGroupOrderUpdated;
use Modules\User\Services\RestaurantGroupOrderService;

final class RestaurantGroupOrderItemDestroyController
{
    public function __construct(
        private readonly RestaurantGroupOrderService $service,
    ) {}

    public function __invoke(int $groupOrder, int $itemId): JsonResponse
    {
        $model = RestaurantGroupOrder::query()->findOrFail($groupOrder);
        $userId = (int) Auth::id();

        $this->service->deleteItem(
            groupOrder: $model,
            userId: $userId,
            itemId: $itemId,
        );

        $model->refresh();
        $payload = $this->service->publicPayload($model, $userId);
        BroadcastAfterResponse::send(new RestaurantGroupOrderUpdated($model, $payload));

        return response()->json([
            'message' => 'Group order item deleted.',
            'data' => $payload,
        ]);
    }
}
