<?php

declare(strict_types=1);

namespace Modules\Supermarket\Http\Controllers\API;

use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Modules\Supermarket\Data\SmRecurringOrderData;
use Modules\Supermarket\Http\Requests\SmRecurringOrderRequest;
use Modules\Supermarket\Http\Requests\SmRecurringOrderRequests\SmRecurringOrderFilterRequest;
use Modules\Supermarket\Http\Resources\SmRecurringOrderResource;
use Modules\Supermarket\Models\SmRecurringOrder;
use Modules\Supermarket\Services\SmRecurringOrderService;

final class SmRecurringOrderController
{
    public function __construct(
        private SmRecurringOrderService $service
    ) {}

    public function index(SmRecurringOrderFilterRequest $request): AnonymousResourceCollection
    {
        $orders = SmRecurringOrder::getQuery()->paginate($request->get('perPage', 20));

        return SmRecurringOrderResource::collection($orders);
    }

    public function store(SmRecurringOrderRequest $request): SmRecurringOrderResource
    {
        $order = $this->service->store(SmRecurringOrderData::from($request->validated()));

        return SmRecurringOrderResource::make($order->load(['user', 'store', 'items']));
    }

    public function show(SmRecurringOrder $smRecurringOrder): SmRecurringOrderResource
    {
        return SmRecurringOrderResource::make($smRecurringOrder->load(['user', 'store', 'items']));
    }

    public function update(SmRecurringOrderRequest $request, SmRecurringOrder $smRecurringOrder): SmRecurringOrderResource
    {
        $order = $this->service->update(SmRecurringOrderData::from($request->validated()), $smRecurringOrder);

        return SmRecurringOrderResource::make($order->load(['user', 'store', 'items']));
    }

    public function destroy(SmRecurringOrder $smRecurringOrder): Response
    {
        $smRecurringOrder->delete();

        return response()->noContent();
    }
}
