<?php

declare(strict_types=1);

namespace Modules\Delivery\Services;

use Modules\Delivery\Models\DeliveryOrder;
use Modules\Resturants\Models\Order;
use Modules\Supermarket\Models\SmOrder;

final class MerchantOrderDeliveryService
{
    public function __construct(
        private readonly DeliveryOrderCreationService $deliveryOrders,
        private readonly MerchantDeliveryLifecycleService $lifecycle,
    ) {}

    public function accepted(Order|SmOrder $merchantOrder): ?DeliveryOrder
    {
        $deliveryOrder = $this->deliveryOrder($merchantOrder);
        if ($deliveryOrder === null || $merchantOrder->accepted_at === null) {
            return $deliveryOrder;
        }

        return $this->lifecycle->accepted(
            deliveryOrder: $deliveryOrder,
            merchantStatus: $this->status($merchantOrder),
            acceptedAt: $merchantOrder->accepted_at,
            preparationMinutes: $merchantOrder->estimated_preparation_minutes,
        );
    }

    public function statusUpdated(Order|SmOrder $merchantOrder): ?DeliveryOrder
    {
        $deliveryOrder = $this->deliveryOrder($merchantOrder);
        if ($deliveryOrder === null) {
            return null;
        }

        $deliveryOrder->forceFill(['merchant_status' => $this->status($merchantOrder)])->save();

        return $deliveryOrder->fresh();
    }

    public function preparationUpdated(Order|SmOrder $merchantOrder): ?DeliveryOrder
    {
        $deliveryOrder = $this->deliveryOrder($merchantOrder);
        if ($deliveryOrder === null) {
            return null;
        }

        $deliveryOrder->forceFill([
            'estimated_preparation_minutes' => $merchantOrder->estimated_preparation_minutes,
            'estimated_ready_at' => $merchantOrder->estimated_ready_at,
        ])->save();

        return $this->lifecycle->preparationUpdated(
            deliveryOrder: $deliveryOrder,
            merchantStatus: $this->status($merchantOrder),
            preparationMinutes: $merchantOrder->estimated_preparation_minutes,
        );
    }

    public function ready(Order|SmOrder $merchantOrder): ?DeliveryOrder
    {
        $deliveryOrder = $this->deliveryOrder($merchantOrder);
        if ($deliveryOrder === null || $merchantOrder->ready_for_pickup_at === null) {
            return $deliveryOrder;
        }

        return $this->lifecycle->ready($deliveryOrder, $this->status($merchantOrder), $merchantOrder->ready_for_pickup_at);
    }

    public function cancelled(Order|SmOrder $merchantOrder, string $reason, ?int $cancelledByUserId = null): ?DeliveryOrder
    {
        $deliveryOrder = $this->deliveryOrder($merchantOrder);

        return $deliveryOrder !== null
            ? $this->lifecycle->cancelled($deliveryOrder, $reason, $cancelledByUserId)
            : null;
    }

    private function deliveryOrder(Order|SmOrder $merchantOrder): ?DeliveryOrder
    {
        return $merchantOrder instanceof Order
            ? $this->deliveryOrders->findForRestaurantOrder($merchantOrder)
            : $this->deliveryOrders->findForSupermarketOrder($merchantOrder);
    }

    private function status(Order|SmOrder $merchantOrder): string
    {
        return $merchantOrder->status?->value ?? (string) $merchantOrder->status;
    }
}
