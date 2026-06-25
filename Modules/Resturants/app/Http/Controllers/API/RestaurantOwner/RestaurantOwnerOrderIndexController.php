<?php

declare(strict_types=1);

namespace Modules\Resturants\Http\Controllers\API\RestaurantOwner;

use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Modules\Resturants\Http\Requests\OrderRequests\OrderFilterRequest;
use Modules\Resturants\Http\Resources\OrderResource;
use Modules\Resturants\Models\Order;
use Modules\Resturants\Support\RestaurantOwnerContext;

final class RestaurantOwnerOrderIndexController
{
    public function __invoke(OrderFilterRequest $request, RestaurantOwnerContext $context): AnonymousResourceCollection
    {
        $restaurantId = $context->restaurantId();
        $filters = $request->input('filter', []);
        $sort = $request->input('sort', '-created_at');
        $sortDirection = str_starts_with($sort, '-') ? 'desc' : 'asc';
        $sortColumn = ltrim($sort, '-');

        $orders = Order::query()
            ->where('restaurant_id', $restaurantId)
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($filters['orderType'] ?? null, fn ($query, $orderType) => $query->where('order_type', $orderType))
            ->when($filters['pickupMode'] ?? null, fn ($query, $pickupMode) => $query->where('pickup_mode', $pickupMode))
            ->when($filters['dateFrom'] ?? null, fn ($query, $dateFrom) => $query->whereDate('created_at', '>=', $dateFrom))
            ->when($filters['dateTo'] ?? null, fn ($query, $dateTo) => $query->whereDate('created_at', '<=', $dateTo))
            ->when($request->boolean('filter.createdToday'), fn ($query) => $query->whereDate('created_at', today()))
            ->when($request->boolean('filter.hasDispute'), fn ($query) => $query->whereHas('disputes'))
            ->when($request->boolean('filter.late'), fn ($query) => $query->whereNotNull('pickup_scheduled_for')->where('pickup_scheduled_for', '<', now()))
            ->with(['user', 'restaurant', 'orderItems.product', 'orderStatusLogs', 'promoCode', 'assignedStaff', 'disputes'])
            ->orderBy($sortColumn, $sortDirection)
            ->paginate($request->integer('perPage', 20));

        return OrderResource::collection($orders);
    }
}
