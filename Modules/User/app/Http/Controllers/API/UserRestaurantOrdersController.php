<?php

declare(strict_types=1);

namespace Modules\User\Http\Controllers\API;

use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Auth;
use Modules\Resturants\Http\Resources\OrderResource;
use Modules\Resturants\Models\Order;

final class UserRestaurantOrdersController
{
    public function __invoke(): AnonymousResourceCollection
    {
        $orders = Order::query()
            ->where('user_id', Auth::id())
            ->with(['restaurant', 'orderItems.product', 'promoCode', 'orderStatusLogs'])
            ->latest()
            ->paginate(20);

        return OrderResource::collection($orders);
    }
}
