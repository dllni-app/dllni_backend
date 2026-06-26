<?php

declare(strict_types=1);

namespace Modules\Resturants\Http\Controllers\API\RestaurantOwner;

use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Modules\Resturants\Enums\OrderStatus;
use Modules\Resturants\Http\Requests\RestaurantOwner\OwnerOrderStatusRequest;
use Modules\Resturants\Models\Order;
use Modules\Resturants\Support\RestaurantOwnerContext;
use Modules\Resturants\Support\RestaurantOwnerOrderPayload;
use Spatie\Activitylog\Facades\Activity;

final class RestaurantOwnerOrderStatusController
{
    /** @throws ValidationException */
    public function __invoke(
        OwnerOrderStatusRequest $request,
        Order $order,
        RestaurantOwnerContext $context,
        RestaurantOwnerOrderPayload $payload
    ): JsonResponse {
        $context->ensureOwnedOrder($order);

        $validated = $request->validated();
        $currentStatus = $this->statusValue($order->status);
        $nextStatus = (string) $validated['status'];

        if ($currentStatus === $nextStatus) {
            return $this->response($order, $payload, 'Order status is already up to date.');
        }

        if (! in_array($nextStatus, $this->allowedNextStatuses($currentStatus), true)) {
            throw ValidationException::withMessages([
                'status' => "Cannot change restaurant order status from {$currentStatus} to {$nextStatus}.",
            ]);
        }

        $update = ['status' => $nextStatus];
        $now = now();

        match ($nextStatus) {
            OrderStatus::Accepted->value => $update += ['accepted_at' => $order->accepted_at ?? $now],
            OrderStatus::Preparing->value => $update += ['preparing_at' => $order->preparing_at ?? $now],
            OrderStatus::ReadyForPickup->value => $update += ['ready_for_pickup_at' => $order->ready_for_pickup_at ?? $now],
            OrderStatus::PickedUp->value => $update += ['picked_up_at' => $order->picked_up_at ?? $now],
            OrderStatus::Completed->value => $update += ['completed_at' => $order->completed_at ?? $now],
            OrderStatus::Cancelled->value => $update += [
                'cancelled_at' => $order->cancelled_at ?? $now,
                'cancellation_reason_code' => $validated['reason'] ?? 'owner_cancelled',
                'cancellation_reason' => $validated['customerMessage'] ?? 'Cancelled by restaurant owner',
            ],
            default => null,
        };

        $order->update($update);

        Activity::causedBy(auth()->user())
            ->performedOn($order)
            ->inLog('orders')
            ->withProperties([
                'restaurant_id' => (int) $order->restaurant_id,
                'order_id' => (int) $order->id,
                'from_status' => $currentStatus,
                'to_status' => $nextStatus,
            ])
            ->log("غيّر حالة الطلب رقم #{$order->order_number} من {$currentStatus} إلى {$nextStatus}");

        return $this->response($order, $payload, 'Order status updated successfully.');
    }

    /** @return list<string> */
    private function allowedNextStatuses(string $currentStatus): array
    {
        return match ($currentStatus) {
            OrderStatus::Pending->value => [OrderStatus::Accepted->value, OrderStatus::Cancelled->value],
            OrderStatus::Accepted->value => [OrderStatus::Preparing->value, OrderStatus::Cancelled->value],
            OrderStatus::Preparing->value => [OrderStatus::ReadyForPickup->value, OrderStatus::Cancelled->value],
            OrderStatus::ReadyForPickup->value => [OrderStatus::PickedUp->value, OrderStatus::Completed->value],
            OrderStatus::PickedUp->value => [OrderStatus::Completed->value],
            default => [],
        };
    }

    private function statusValue(mixed $status): string
    {
        return $status instanceof OrderStatus ? $status->value : (string) $status;
    }

    private function response(Order $order, RestaurantOwnerOrderPayload $payload, string $message): JsonResponse
    {
        $order->refresh()->load([
            'user.addresses',
            'userAddress',
            'restaurant',
            'orderItems.product',
            'orderStatusLogs',
            'promoCode',
            'assignedStaff',
            'disputes',
        ]);

        return response()->json([
            'data' => $payload->build($order),
            'message' => $message,
        ]);
    }
}
