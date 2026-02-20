<?php

declare(strict_types=1);

namespace Modules\Resturants\Http\Controllers\API;

use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Resturants\Enums\OrderStatus;
use Modules\Resturants\Http\Resources\OrderResource;
use Modules\Resturants\Models\Order;

final class OrderRejectController
{
    public function __invoke(Order $order): JsonResource
    {
        $order->update([
            'status' => OrderStatus::Cancelled,
            'cancelled_at' => now(),
            'cancellation_reason' => 'Rejected by seller',
        ]);

        $order->load([
            'user', 'restaurant', 'orderItems.product', 'orderStatusLogs',
            'promoCode', 'assignedStaff', 'disputes',
        ]);

        return OrderResource::make($order)->additional(['message' => 'Order rejected successfully.']);
    }
}
