<?php

declare(strict_types=1);

namespace Modules\Resturants\Http\Controllers\API;

use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Modules\Resturants\Data\OrderData;
use Modules\Resturants\Http\Requests\OrderRequest;
use Modules\Resturants\Http\Requests\OrderRequests\OrderFilterRequest;
use Modules\Resturants\Http\Resources\OrderResource;
use Modules\Resturants\Models\Order;
use Modules\Resturants\Services\OrderService;
use Throwable;

final class OrderController
{
    public function __construct(
        private OrderService $orderService
    ) {}

    public function index(OrderFilterRequest $request): AnonymousResourceCollection
    {
        $orders = Order::getQuery()
            ->with(['user', 'restaurant', 'orderItems', 'orderStatusLogs', 'promoCode', 'assignedStaff', 'disputes'])
            ->paginate($request->get('perPage', 20));

        return OrderResource::collection($orders);
    }

    /** @throws Throwable */
    public function store(OrderRequest $request): OrderResource
    {
        $order = $this->orderService->store(
            OrderData::from($request->validated())
        );

        return OrderResource::make(
            $order->load(['user', 'restaurant', 'orderItems', 'orderStatusLogs', 'promoCode', 'assignedStaff', 'disputes'])
        );
    }

    public function show(Order $order): OrderResource
    {
        $order->load([
            'user', 'restaurant', 'orderItems', 'orderStatusLogs', 'promoCode', 'assignedStaff', 'disputes',
        ]);

        return OrderResource::make($order);
    }

    /** @throws Throwable */
    public function update(OrderRequest $request, Order $order): OrderResource
    {
        $updated = $this->orderService->update(
            OrderData::from($request->validated()),
            $order
        );

        return OrderResource::make(
            $updated->load(['user', 'restaurant', 'orderItems', 'orderStatusLogs', 'promoCode', 'assignedStaff', 'disputes'])
        );
    }

    public function destroy(Order $order): Response
    {
        $order->delete();

        return response()->noContent();
    }
}
