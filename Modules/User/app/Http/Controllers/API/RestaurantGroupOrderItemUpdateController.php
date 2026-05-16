<?php

declare(strict_types=1);

namespace Modules\User\Http\Controllers\API;

use App\Support\Broadcast\BroadcastAfterResponse;
use Illuminate\Http\JsonResponse;
use Modules\Resturants\Models\RestaurantGroupOrder;
use Modules\User\Events\RestaurantGroupOrderUpdated;
use Modules\User\Http\Requests\RestaurantGroupOrderItemUpdateRequest;
use Modules\User\Services\RestaurantGroupOrderService;

final class RestaurantGroupOrderItemUpdateController
{
    public function __construct(
        private readonly RestaurantGroupOrderService $service,
    ) {}

    public function __invoke(RestaurantGroupOrderItemUpdateRequest $request, int $groupOrder, int $itemId): JsonResponse
    {
        $model = RestaurantGroupOrder::query()->findOrFail($groupOrder);

        $this->service->updateItem(
            groupOrder: $model,
            userId: (int) $request->user()->id,
            itemId: $itemId,
            quantity: (int) $request->integer('quantity'),
            modifierIds: $request->input('modifierIds', []),
            substituteProductId: $request->input('substituteProductId'),
            note: $request->input('specialInstructions') ?? $request->input('note'),
        );

        $model->refresh();
        $payload = $this->service->publicPayload($model, (int) $request->user()->id);
        BroadcastAfterResponse::send(new RestaurantGroupOrderUpdated($model, $payload));

        return response()->json([
            'message' => 'Group order item updated.',
            'data' => $payload,
        ]);
    }
}
