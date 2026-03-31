<?php

declare(strict_types=1);

namespace Modules\User\Http\Controllers\API;

use Modules\Resturants\Http\Resources\OrderResource;
use Modules\Resturants\Models\Order;

final class UserRestaurantOrderShowController
{
    public function __invoke(int $order): OrderResource
    {
        $model = Order::query()
            ->where('user_id', auth()->id())
            ->with(['restaurant', 'orderItems.product', 'promoCode'])
            ->findOrFail($order);

        return OrderResource::make($model);
    }
}
