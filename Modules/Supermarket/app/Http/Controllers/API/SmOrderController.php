<?php

declare(strict_types=1);

namespace Modules\Supermarket\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Modules\Supermarket\Data\SmOrderData;
use Modules\Supermarket\Http\Requests\SmOrderRequest;
use Modules\Supermarket\Http\Requests\SmOrderRequests\SmOrderFilterRequest;
use Modules\Supermarket\Http\Resources\SmOrderResource;
use Modules\Supermarket\Models\SmOrder;
use Modules\Supermarket\Services\SmOrderNotificationService;
use Modules\Supermarket\Services\SmOrderService;

final class SmOrderController
{
    public function __construct(
        private SmOrderService $service,
        private SmOrderNotificationService $notifications,
    ) {}

    public function index(SmOrderFilterRequest $request): AnonymousResourceCollection
    {
        $orders = SmOrder::getQuery()
            ->where('store_id', $request->integer('store_id'))
            ->with(['items.product', 'statusLogs'])
            ->paginate($request->get('perPage', 20));

        return SmOrderResource::collection($orders);
    }

    public function hourlyCount(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->service->getWeeklyOrderCountsByStatus($request->integer('store_id')),
        ]);
    }

    public function store(SmOrderRequest $request): SmOrderResource
    {
        $order = $this->service->store(SmOrderData::from($request->validated()));
        $this->notifications->notifyCreated($order);

        return SmOrderResource::make($order->load(['customer', 'store', 'coupon', 'items.product', 'statusLogs', 'disputes']));
    }

    public function show(SmOrder $smOrder): SmOrderResource
    {
        return SmOrderResource::make($smOrder->load(['customer', 'store', 'coupon', 'items.product.media', 'statusLogs', 'disputes']));
    }

    public function update(SmOrderRequest $request, SmOrder $smOrder): SmOrderResource
    {
        $previousStatus = $smOrder->status?->value ?? (string) $smOrder->status;
        $order = $this->service->update(SmOrderData::from($request->validated()), $smOrder);
        $nextStatus = $order->status?->value ?? (string) $order->status;
        $this->notifications->notifyStatusChanged($order, $previousStatus, $nextStatus, 'owner');

        return SmOrderResource::make($order->load(['customer', 'store', 'coupon', 'items.product', 'statusLogs', 'disputes']));
    }

    public function destroy(SmOrder $smOrder): Response
    {
        $smOrder->delete();

        return response()->noContent();
    }
}
