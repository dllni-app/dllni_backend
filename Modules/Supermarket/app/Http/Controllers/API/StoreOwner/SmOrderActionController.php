<?php

declare(strict_types=1);

namespace Modules\Supermarket\Http\Controllers\API\StoreOwner;

use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Supermarket\Enums\SmOrderStatus;
use Modules\Supermarket\Http\Requests\SmOrderRejectRequest;
use Modules\Supermarket\Http\Resources\SmOrderResource;
use Modules\Supermarket\Models\SmOrder;

final class SmOrderActionController
{
    public function accept(SmOrder $order): JsonResource
    {
        $order->update([
            'status' => SmOrderStatus::Accepted,
        ]);

        $order->load([
            'customer',
            'store',
            'coupon',
            'items.product',
            'statusLogs',
            'disputes',
        ]);

        return SmOrderResource::make($order)->additional([
            'message' => 'Order accepted successfully.',
        ]);
    }

    public function reject(SmOrderRejectRequest $request, SmOrder $order): JsonResource
    {
        $order->update([
            'status' => SmOrderStatus::Cancelled,
            'cancelled_at' => now(),
            'cancellation_reason' => $request->input('cancellationReason'),
        ]);

        $order->load([
            'customer',
            'store',
            'coupon',
            'items.product',
            'statusLogs',
            'disputes',
        ]);

        return SmOrderResource::make($order)->additional([
            'message' => 'Order rejected successfully.',
        ]);
    }
}
