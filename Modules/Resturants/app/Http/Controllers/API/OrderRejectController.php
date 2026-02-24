<?php

declare(strict_types=1);

namespace Modules\Resturants\Http\Controllers\API;

use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Resturants\Enums\OrderStatus;
use Modules\Resturants\Http\Requests\OrderRejectRequest;
use Modules\Resturants\Http\Resources\OrderResource;
use Modules\Resturants\Models\Order;

final class OrderRejectController
{
    public function __invoke(OrderRejectRequest $request, Order $order): JsonResource
    {
        $validated = $request->validated();

        $order->update([
            'status' => OrderStatus::Cancelled,
            'cancelled_at' => now(),
            'cancellation_reason_code' => $validated['reason'],
            'cancellation_reason' => $validated['customerMessage'] ?? 'Rejected by seller',
        ]);

        $order->load([
            'user', 'restaurant', 'orderItems.product', 'orderStatusLogs',
            'promoCode', 'assignedStaff', 'disputes',
        ]);

        return OrderResource::make($order)->additional(['message' => 'Order rejected successfully.']);
    }
}
