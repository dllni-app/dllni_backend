<?php

declare(strict_types=1);

namespace Modules\Delivery\Http\Controllers\API\User;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Delivery\Http\Resources\DeliveryOrderResource;
use Modules\Delivery\Models\DeliveryOrder;

final class DeliveryUserOrderController
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $perPage = max(1, min(50, (int) $request->integer('perPage', 20)));

        $orders = DeliveryOrder::query()
            ->ownedByUser((int) $user->id)
            ->with(['company', 'driver.user', 'driver.latestLocation', 'events'])
            ->latest('updated_at')
            ->paginate($perPage);

        return DeliveryOrderResource::collection($orders)->response();
    }

    public function show(Request $request, int $order): JsonResponse
    {
        $user = $request->user();

        $deliveryOrder = DeliveryOrder::query()
            ->ownedByUser((int) $user->id)
            ->with(['company', 'driver.user', 'driver.latestLocation', 'events'])
            ->findOrFail($order);

        return response()->json([
            'data' => DeliveryOrderResource::make($deliveryOrder),
        ]);
    }
}
