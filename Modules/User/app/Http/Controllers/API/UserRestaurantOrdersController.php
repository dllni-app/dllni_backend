<?php

declare(strict_types=1);

namespace Modules\User\Http\Controllers\API;

use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Modules\Resturants\Http\Resources\OrderResource;
use Modules\Resturants\Models\Order;

final class UserRestaurantOrdersController
{
    public function __invoke(): AnonymousResourceCollection
    {
        $orders = Order::query()
            ->where('user_id', auth()->id())
            ->with(['restaurant', 'orderItems.product', 'promoCode'])
            ->latest()
            ->paginate(20);

        return OrderResource::collection($orders);
    }
}
