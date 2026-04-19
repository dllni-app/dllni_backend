<?php

declare(strict_types=1);

namespace Modules\User\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Modules\Resturants\Models\RestaurantGroupOrder;
use Modules\User\Events\RestaurantGroupOrderUpdated;
use Modules\User\Http\Requests\RestaurantGroupOrderItemStoreRequest;
use Modules\User\Services\RestaurantGroupOrderService;

final class RestaurantGroupOrderItemStoreController
{
    public function __construct(
        private readonly RestaurantGroupOrderService $service,
    ) {}

    public function __invoke(RestaurantGroupOrderItemStoreRequest $request, int $groupOrder): JsonResponse
    {
        $model = RestaurantGroupOrder::query()->findOrFail($groupOrder);

        $this->service->addItem(
            groupOrder: $model,
            userId: (int) $request->user()->id,
            productId: (int) $request->integer('productId'),
            quantity: (int) $request->integer('quantity'),
            modifierIds: $request->input('modifierIds', []),
            substituteProductId: $request->input('substituteProductId'),
            note: $request->input('specialInstructions') ?? $request->input('note'),
        );

        $model->refresh();
        $payload = $this->service->publicPayload($model, (int) $request->user()->id);
        RestaurantGroupOrderUpdated::dispatch($model, $payload['groupOrder']);

        return response()->json([
            'message' => 'Item added to group order.',
            'data' => $payload,
        ], 201);
    }
}
