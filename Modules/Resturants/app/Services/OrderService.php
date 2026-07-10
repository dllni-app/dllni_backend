<?php

declare(strict_types=1);

namespace Modules\Resturants\Services;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Modules\Delivery\Services\MerchantOrderDeliveryService;
use Modules\Resturants\Data\OrderData;
use Modules\Resturants\Enums\OrderStatus;
use Modules\Resturants\Models\Order;

final class OrderService
{
    public function __construct(
        private readonly RestaurantOrderNotificationService $notifications,
        private readonly MerchantOrderDeliveryService $merchantDelivery,
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

    public function accept(Order $order, ?int $preparationMinutes, ?int $assignedStaffId, ?string $kitchenNotes): Order
    {
        $accepted = DB::transaction(function () use ($order, $preparationMinutes, $assignedStaffId, $kitchenNotes): Order {
            $lockedOrder = Order::query()->lockForUpdate()->findOrFail($order->id);
            if ($lockedOrder->status !== OrderStatus::Pending) {
                throw new InvalidArgumentException('Only pending restaurant orders can be accepted.');
            }

            $acceptedAt = now();
            $lockedOrder->forceFill([
                'status' => OrderStatus::Accepted,
                'accepted_at' => $acceptedAt,
                'estimated_preparation_minutes' => $preparationMinutes,
                'estimated_ready_at' => $preparationMinutes !== null ? $acceptedAt->copy()->addMinutes($preparationMinutes) : null,
                'assigned_staff_id' => $assignedStaffId,
                'kitchen_notes' => $kitchenNotes,
            ])->save();

            return $lockedOrder->fresh();
        });

        $this->merchantDelivery->accepted($accepted);

        return $accepted->fresh();
    }

    public function updatePreparationEstimate(Order $order, ?int $preparationMinutes): Order
    {
        $updated = DB::transaction(function () use ($order, $preparationMinutes): Order {
            $lockedOrder = Order::query()->lockForUpdate()->findOrFail($order->id);
            if (! in_array($lockedOrder->status, [OrderStatus::Accepted, OrderStatus::Preparing], true)) {
                throw new InvalidArgumentException('Preparation estimates can only be changed while accepted or preparing.');
            }

            $lockedOrder->forceFill([
                'estimated_preparation_minutes' => $preparationMinutes,
                'estimated_ready_at' => $preparationMinutes !== null ? now()->addMinutes($preparationMinutes) : null,
            ])->save();

            return $lockedOrder->fresh();
        });

        $this->merchantDelivery->preparationUpdated($updated);

        return $updated->fresh();
    }
}
