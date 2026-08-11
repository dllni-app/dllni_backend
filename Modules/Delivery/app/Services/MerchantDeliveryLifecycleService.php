<?php

declare(strict_types=1);

namespace Modules\Delivery\Services;

use Carbon\CarbonInterface;
use Modules\Delivery\Enums\DeliveryOrderStatus;
use Modules\Delivery\Models\DeliveryOrder;

final class MerchantDeliveryLifecycleService
{
    public function __construct(
        private readonly DeliveryOrderService $deliveryOrders,
        private readonly DeliveryNotificationService $notifications,
    ) {}

    public function accepted(DeliveryOrder $deliveryOrder, string $merchantStatus, CarbonInterface $acceptedAt, ?int $preparationMinutes): DeliveryOrder
    {
        $deliveryOrder->forceFill([
            'merchant_status' => $merchantStatus,
            'merchant_accepted_at' => $acceptedAt,
            'estimated_preparation_minutes' => $preparationMinutes,
            'estimated_ready_at' => $preparationMinutes !== null ? $acceptedAt->copy()->addMinutes($preparationMinutes) : null,
            'merchant_ready_at' => null,
        ])->save();

        return $this->deliveryOrders->startDispatch($deliveryOrder, 'Merchant accepted order; driver search started.');
    }

    public function preparationUpdated(DeliveryOrder $deliveryOrder, string $merchantStatus, ?int $preparationMinutes): DeliveryOrder
    {
        $deliveryOrder->forceFill([
            'merchant_status' => $merchantStatus,
            'estimated_preparation_minutes' => $preparationMinutes,
        ])->save();

        $updated = $deliveryOrder->fresh(['driver.user']);
        if ($updated->driver_id !== null) {
            $this->notifications->notifyMerchantPreparationUpdated($updated);
        }

        return $updated;
    }

    public function ready(DeliveryOrder $deliveryOrder, string $merchantStatus, CarbonInterface $readyAt): DeliveryOrder
    {
        $deliveryOrder->forceFill([
            'merchant_status' => $merchantStatus,
            'merchant_ready_at' => $readyAt,
        ])->save();

        $updated = $deliveryOrder->fresh(['driver.user']);
        if ($updated->driver_id !== null) {
            $this->notifications->notifyMerchantReady($updated);

            return $updated;
        }

        return match (DeliveryOrderStatus::tryFrom((string) $updated->status)) {
            DeliveryOrderStatus::Stopped => $this->deliveryOrders->retryDispatch($updated),
            DeliveryOrderStatus::WaitingMerchantReady => $this->deliveryOrders->startDispatch($updated, 'Merchant marked order ready; driver search started.'),
            default => $updated,
        };
    }

    public function cancelled(DeliveryOrder $deliveryOrder, string $reason, ?int $cancelledByUserId = null): DeliveryOrder
    {
        $deliveryOrder->forceFill(['merchant_status' => 'cancelled'])->save();

        return $this->deliveryOrders->cancel($deliveryOrder, $reason, $cancelledByUserId);
    }
}
