<?php

declare(strict_types=1);

namespace Modules\Supermarket\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Modules\Supermarket\Data\SmOrderData;
use Modules\Supermarket\Http\Requests\SmOrderRequest;
use Modules\Supermarket\Http\Requests\SmOrderRequests\SmOrderFilterRequest;
use Modules\Supermarket\Http\Resources\SmOrderResource;
use Modules\Supermarket\Models\SmOrder;
use Modules\Supermarket\Services\SmOrderService;

final class SmOrderController
{
    public function __construct(
        private SmOrderService $service
    ) {}

    public function index(SmOrderFilterRequest $request): AnonymousResourceCollection
    {
        $orders = SmOrder::getQuery()->paginate($request->get('perPage', 20));

        return SmOrderResource::collection($orders->load(['items']));
    }

    public function hourlyCount(): JsonResponse
    {
        return response()->json([
            'data' => $this->service->getWeeklyOrderCountsByStatus(),
        ]);
    }

    public function store(SmOrderRequest $request): SmOrderResource
    {
        $order = $this->service->store(SmOrderData::from($request->validated()));

        return SmOrderResource::make($order->load(['customer', 'store', 'coupon', 'items', 'statusLogs', 'disputes']));
    }

    public function show(SmOrder $smOrder): SmOrderResource
    {
        return SmOrderResource::make($smOrder->load(['customer', 'store', 'coupon', 'items', 'statusLogs', 'disputes']));
    }

    public function update(SmOrderRequest $request, SmOrder $smOrder): SmOrderResource
    {
        $order = $this->service->update(SmOrderData::from($request->validated()), $smOrder);

        return SmOrderResource::make($order->load(['customer', 'store', 'coupon', 'items', 'statusLogs', 'disputes']));
    }

    public function destroy(SmOrder $smOrder): Response
    {
        $smOrder->delete();

        return response()->noContent();
    }
}
