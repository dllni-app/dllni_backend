<?php

declare(strict_types=1);

namespace Modules\Delivery\Services;

use Modules\Delivery\Enums\DeliveryOrderStatus;
use Modules\Delivery\Models\DeliveryOrder;
use Modules\Resturants\Enums\OrderStatus;
use Modules\Resturants\Models\Order;
use Modules\Resturants\Models\OrderStatusLog;
use Modules\Supermarket\Enums\SmOrderStatus;
use Modules\Supermarket\Models\SmOrder;
use Modules\Supermarket\Models\SmOrderStatusLog;

final class DeliverySourceOrderSyncService
{
    public function sync(DeliveryOrder $deliveryOrder, DeliveryOrderStatus $deliveryStatus, ?string $note = null): void
    {
        $source = $deliveryOrder->relationLoaded('source')
            ? $deliveryOrder->source
            : $deliveryOrder->source()->first();

        if ($source instanceof Order) {
            $this->syncRestaurantOrder($source, $deliveryStatus, $note);
            return;
        }

        if ($source instanceof SmOrder) {
            $this->syncSupermarketOrder($source, $deliveryStatus, $note);
        }
    }

    private function syncRestaurantOrder(Order $order, DeliveryOrderStatus $deliveryStatus, ?string $note): void
    {
        $current = $order->status?->value ?? (string) $order->status;
        $changes = $this->restaurantChanges($deliveryStatus, $order);

        if ($changes === []) {
            return;
        }

        $nextStatus = (string) ($changes['status'] ?? $current);
        $order->forceFill($changes)->save();

        if ($nextStatus !== $current) {
            OrderStatusLog::query()->create([
                'order_id' => $order->id,
                'from_status' => $current,
                'to_status' => $nextStatus,
                'note' => $note ?? 'Delivery lifecycle sync.',
            ]);
        }
    }

    private function syncSupermarketOrder(SmOrder $order, DeliveryOrderStatus $deliveryStatus, ?string $note): void
    {
        $current = $order->status?->value ?? (string) $order->status;
        $changes = $this->supermarketChanges($deliveryStatus, $order);

        if ($changes === []) {
            return;
        }

        $nextStatus = (string) ($changes['status'] ?? $current);
        $order->forceFill($changes)->save();

        if ($nextStatus !== $current) {
            SmOrderStatusLog::query()->create([
                'order_id' => $order->id,
                'from_status' => $current,
                'to_status' => $nextStatus,
                'notes' => $note ?? 'Delivery lifecycle sync.',
                'changed_by_user_id' => $order->customer_id,
            ]);
        }
    }

    /** @return array<string, mixed> */
    private function restaurantChanges(DeliveryOrderStatus $deliveryStatus, Order $order): array
    {
        return match ($deliveryStatus) {
            DeliveryOrderStatus::PickedUp => [
                'status' => OrderStatus::PickedUp->value,
                'picked_up_at' => $order->picked_up_at ?? now(),
            ],
            DeliveryOrderStatus::Delivered,
            DeliveryOrderStatus::Completed => [
                'status' => OrderStatus::Completed->value,
                'completed_at' => $order->completed_at ?? now(),
                'customer_pickup_confirmed_at' => $order->customer_pickup_confirmed_at ?? now(),
            ],
            DeliveryOrderStatus::Cancelled => [
                'status' => OrderStatus::Cancelled->value,
                'cancelled_at' => $order->cancelled_at ?? now(),
                'cancellation_reason' => $order->cancellation_reason ?? 'Delivery order '.$deliveryStatus->value,
            ],
            default => [],
        };
    }

    /** @return array<string, mixed> */
    private function supermarketChanges(DeliveryOrderStatus $deliveryStatus, SmOrder $order): array
    {
        return match ($deliveryStatus) {
            DeliveryOrderStatus::PickedUp => [
                'status' => SmOrderStatus::PickedUp->value,
                'picked_up_at' => $order->picked_up_at ?? now(),
            ],
            DeliveryOrderStatus::Delivered,
            DeliveryOrderStatus::Completed => [
                'status' => SmOrderStatus::Completed->value,
                'customer_pickup_confirmed_at' => $order->customer_pickup_confirmed_at ?? now(),
            ],
            DeliveryOrderStatus::Cancelled => [
                'status' => SmOrderStatus::Cancelled->value,
                'cancelled_at' => $order->cancelled_at ?? now(),
                'cancellation_reason' => $order->cancellation_reason ?? 'Delivery order '.$deliveryStatus->value,
            ],
            default => [],
        };
    }
}
