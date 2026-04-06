<?php

declare(strict_types=1);

namespace Modules\User\Http\Controllers\API;

use Illuminate\Support\Facades\Auth;
use Modules\Resturants\Http\Resources\OrderResource;
use Modules\Resturants\Models\Order;

final class UserRestaurantOrderShowController
{
    public function __invoke(int $order): OrderResource
    {
        $model = Order::query()
            ->where('user_id', Auth::id())
            ->with(['restaurant', 'orderItems.product', 'promoCode', 'orderStatusLogs'])
            ->findOrFail($order);

        return OrderResource::make($model);
    }
}
