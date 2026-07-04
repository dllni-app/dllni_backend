<?php

declare(strict_types=1);

namespace Modules\Resturants\Services;

use Illuminate\Support\Facades\DB;
use Modules\Resturants\Data\OrderData;
use Modules\Resturants\Models\Order;

final class OrderService
{
    public function __construct(
        private readonly RestaurantOrderNotificationService $notifications,
    ) {}

    public function store(OrderData $data): Order
    {
        $order = DB::transaction(static function () use ($data) {
            return Order::create($data->onlyModelAttributes());
        });

        $this->notifications->notifyCreated($order);

        return $order;
    }

    public function update(OrderData $data, Order $order): Order
    {
        $previousStatus = $order->status?->value ?? (string) $order->status;

        $updated = DB::transaction(static function () use ($data, $order) {
            tap($order)->update($data->onlyModelAttributes());

            return $order;
        });

        $nextStatus = $updated->status?->value ?? (string) $updated->status;
        $this->notifications->notifyStatusChanged($updated, $previousStatus, $nextStatus, 'owner');

        return $updated;
    }
}
