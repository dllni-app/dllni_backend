<?php

declare(strict_types=1);

namespace Modules\Resturants\Http\Controllers\API;

use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Modules\Resturants\Http\Requests\RestaurantRecurringOrderRequests\RestaurantRecurringOrderFilterRequest;
use Modules\Resturants\Http\Resources\RestaurantRecurringOrderResource;
use Modules\Resturants\Models\RestaurantRecurringOrder;

final class RestaurantRecurringOrderController
{
    public function index(RestaurantRecurringOrderFilterRequest $request): AnonymousResourceCollection
    {
        $orders = RestaurantRecurringOrder::getQuery()
            ->with(['user', 'restaurant', 'items'])
            ->paginate($request->get('perPage', 10));

        return RestaurantRecurringOrderResource::collection($orders);
    }

    public function show(RestaurantRecurringOrder $restaurant_recurring_order): RestaurantRecurringOrderResource
    {
        $restaurant_recurring_order->load(['user', 'restaurant', 'items']);

        return RestaurantRecurringOrderResource::make($restaurant_recurring_order);
    }
}
