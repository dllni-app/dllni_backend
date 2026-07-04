<?php

declare(strict_types=1);

namespace Modules\Resturants\Http\Controllers\API;

use App\Services\ActivityLogService;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Resturants\Enums\OrderStatus;
use Modules\Resturants\Http\Requests\OrderRejectRequest;
use Modules\Resturants\Http\Resources\OrderResource;
use Modules\Resturants\Models\Order;
use Modules\Resturants\Services\RestaurantOrderNotificationService;

final class OrderRejectController
{
    public function __construct(
        private ActivityLogService $activityLogService,
        private RestaurantOrderNotificationService $notifications,
    ) {}

    public function __invoke(OrderRejectRequest $request, Order $order): JsonResource
    {
        $validated = $request->validated();
        $previousStatus = $order->status?->value ?? (string) $order->status;

        $order->update([
            'status' => OrderStatus::Cancelled,
            'cancelled_at' => now(),
            'cancellation_reason_code' => $validated['reason'],
            'cancellation_reason' => $validated['customerMessage'] ?? 'Cancelled by seller',
        ]);

        $this->activityLogService->logOrderRejected((int) $order->id, $order->order_number, (int) $order->restaurant_id);
        $this->notifications->notifyStatusChanged($order->refresh(), $previousStatus, OrderStatus::Cancelled->value, 'owner');

        $order->load([
            'user', 'restaurant', 'orderItems.product', 'orderStatusLogs',
            'promoCode', 'assignedStaff', 'disputes',
        ]);

        return OrderResource::make($order)->additional(['message' => 'Order rejected successfully.']);
    }
}
