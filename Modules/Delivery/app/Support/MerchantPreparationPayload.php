<?php

declare(strict_types=1);

namespace Modules\Delivery\Support;

use Modules\Delivery\Models\DeliveryOrder;

final class MerchantPreparationPayload
{
    /** @return array<string, mixed> */
    public static function forOrder(DeliveryOrder $order): array
    {
        $isReady = $order->merchant_ready_at !== null;
        $estimatedReadyAt = $order->estimated_ready_at;

        return [
            'status' => $order->merchant_status,
            'isReady' => $isReady,
            'estimatedPreparationMinutes' => $order->estimated_preparation_minutes,
            'estimatedReadyAt' => $estimatedReadyAt?->toIso8601String(),
            'readyAt' => $order->merchant_ready_at?->toIso8601String(),
            'hasEstimate' => $estimatedReadyAt !== null,
            'isEstimateOverdue' => ! $isReady && $estimatedReadyAt?->isPast() === true,
        ];
    }
}
