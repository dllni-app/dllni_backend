<?php

declare(strict_types=1);

namespace Modules\Resturants\Http\Controllers\API;

use App\Services\ActivityLogService;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Validation\ValidationException;
use Modules\Resturants\Http\Requests\OrderAcceptRequest;
use Modules\Resturants\Http\Resources\OrderResource;
use Modules\Resturants\Models\Order;
use Modules\Resturants\Models\RestaurantStaff;
use Modules\Resturants\Services\OrderService;
use Modules\Resturants\Services\RestaurantOrderNotificationService;
use Modules\Resturants\Support\RestaurantOwnerContext;

final class OrderAcceptController
{
    public function __construct(
        private ActivityLogService $activityLogService,
        private RestaurantOrderNotificationService $notifications,
        private OrderService $orders,
        private RestaurantOwnerContext $ownerContext,
    ) {}

    /** @throws ValidationException */
    public function __invoke(OrderAcceptRequest $request, Order $order): JsonResource
    {
        $this->ownerContext->ensureOwnedOrder($order);

        $validated = $request->validated();
        $assignedEmployeeId = isset($validated['assignedEmployeeId'])
            ? (int) $validated['assignedEmployeeId']
            : null;

        if ($assignedEmployeeId !== null) {
            $isActiveRestaurantEmployee = RestaurantStaff::query()
                ->where('restaurant_id', $this->ownerContext->restaurantId())
                ->where('user_id', $assignedEmployeeId)
                ->where('is_active', true)
                ->exists();

            if (! $isActiveRestaurantEmployee) {
                throw ValidationException::withMessages([
                    'assignedEmployeeId' => 'The assigned employee must be an active employee of this restaurant.',
                ]);
            }
        }

        $previousStatus = $order->status?->value ?? (string) $order->status;

        $order = $this->orders->accept(
            order: $order,
            preparationMinutes: isset($validated['preparationTimeMinutes']) ? (int) $validated['preparationTimeMinutes'] : null,
            assignedStaffId: $assignedEmployeeId,
            kitchenNotes: $validated['kitchenNotes'] ?? null,
        );

        $this->activityLogService->logOrderAccepted((int) $order->id, $order->order_number, (int) $order->restaurant_id);
        $this->notifications->notifyStatusChanged($order->refresh(), $previousStatus, 'accepted', 'owner');

        $order->load([
            'user', 'restaurant', 'orderItems.product', 'orderStatusLogs',
            'promoCode', 'assignedStaff', 'disputes',
        ]);

        return OrderResource::make($order)->additional(['message' => 'Order accepted successfully.']);
    }
}
