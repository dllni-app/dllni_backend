<?php

declare(strict_types=1);

namespace Modules\Resturants\Http\Controllers\API;

use App\Services\ActivityLogService;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Resturants\Enums\OrderStatus;
use Modules\Resturants\Http\Requests\OrderAcceptRequest;
use Modules\Resturants\Http\Resources\OrderResource;
use Modules\Resturants\Models\Order;
use Modules\Resturants\Services\RestaurantOrderNotificationService;

final class OrderAcceptController
{
    public function __construct(
        private ActivityLogService $activityLogService,
        private RestaurantOrderNotificationService $notifications,
    ) {}

    public function __invoke(OrderAcceptRequest $request, Order $order): JsonResource
    {
        $validated = $request->validated();
        $previousStatus = $order->status?->value ?? (string) $order->status;

        $order->update([
            'status' => OrderStatus::Accepted,
            'accepted_at' => now(),
            'estimated_preparation_minutes' => $validated['preparationTimeMinutes'],
            'assigned_staff_id' => $validated['assignedEmployeeId'] ?? null,
            'kitchen_notes' => $validated['kitchenNotes'] ?? null,
        ]);

        $this->activityLogService->logOrderAccepted((int) $order->id, $order->order_number, (int) $order->restaurant_id);
        $this->notifications->notifyStatusChanged($order->refresh(), $previousStatus, OrderStatus::Accepted->value, 'owner');

        $order->load([
            'user', 'restaurant', 'orderItems.product', 'orderStatusLogs',
            'promoCode', 'assignedStaff', 'disputes',
        ]);

        return OrderResource::make($order)->additional(['message' => 'Order accepted successfully.']);
    }
}
