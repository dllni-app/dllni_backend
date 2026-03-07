<?php

declare(strict_types=1);

namespace Modules\Resturants\Support;

use Modules\Resturants\Enums\OrderStatus;
use Modules\Resturants\Http\Resources\OrderResource;
use Modules\Resturants\Models\Order;

final class RestaurantOwnerOrderPayload
{
    public function build(Order $order): array
    {
        $status = $order->status?->value ?? $order->status;
        $canEditItems = in_array($status, [OrderStatus::Pending->value, OrderStatus::Accepted->value], true);

        return [
            ...OrderResource::make($order)->resolve(),
            'canEditItems' => $canEditItems,
            'paymentBreakdown' => [
                'subtotal' => (float) ($order->subtotal ?? 0),
                'deliveryFee' => 0.0,
                'serviceFee' => (float) ($order->service_fee ?? 0),
                'discount' => (float) ($order->discount_amount ?? 0),
                'total' => (float) ($order->total_amount ?? 0),
            ],
        ];
    }

    public function canEdit(Order $order): bool
    {
        $status = $order->status?->value ?? $order->status;

        return in_array($status, [OrderStatus::Pending->value, OrderStatus::Accepted->value], true);
    }
}
