<?php

declare(strict_types=1);

namespace Modules\User\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Modules\Resturants\Enums\OrderStatus;
use Modules\Resturants\Models\Order;
use Modules\Resturants\Services\RestaurantOrderNotificationService;
use Modules\Supermarket\Enums\SmOrderStatus;
use Modules\Supermarket\Models\SmOrder;
use Modules\Supermarket\Services\SmOrderNotificationService;
use Modules\User\Http\Requests\UserOrderCancelRequest;
use Modules\User\Services\UserOrderHubService;

final class UserOrderCancelController
{
    public function __construct(
        private readonly UserOrderHubService $orders,
        private readonly RestaurantOrderNotificationService $restaurantNotifications,
        private readonly SmOrderNotificationService $supermarketNotifications,
    ) {}

    public function __invoke(UserOrderCancelRequest $request, string $section, int $orderId): JsonResponse
    {
        $this->validateSection($section);

        $userId = (int) $request->user()->id;
        $previousStatus = $this->currentStatus($userId, $section, $orderId);

        $payload = $this->orders->cancel(
            userId: $userId,
            section: $section,
            orderId: $orderId,
            reason: $request->input('reason'),
        );

        $this->notifyCancellation($userId, $section, $orderId, $previousStatus);

        return response()->json([
            'data' => $payload,
        ]);
    }

    private function validateSection(string $section): void
    {
        $validator = validator(['section' => $section], [
            'section' => ['required', Rule::in(['restaurant', 'supermarket'])],
        ]);

        if ($validator->fails()) {
            throw ValidationException::withMessages($validator->errors()->toArray());
        }
    }

    private function currentStatus(int $userId, string $section, int $orderId): string
    {
        if ($section === 'restaurant') {
            $order = Order::query()
                ->where('user_id', $userId)
                ->findOrFail($orderId);

            return $order->status?->value ?? (string) $order->status;
        }

        $order = SmOrder::query()
            ->where('customer_id', $userId)
            ->findOrFail($orderId);

        return $order->status?->value ?? (string) $order->status;
    }

    private function notifyCancellation(int $userId, string $section, int $orderId, string $previousStatus): void
    {
        if ($section === 'restaurant') {
            if (in_array($previousStatus, [OrderStatus::Cancelled->value, OrderStatus::Completed->value], true)) {
                return;
            }

            $order = Order::query()
                ->where('user_id', $userId)
                ->with(['user', 'restaurant.user'])
                ->find($orderId);

            if ($order && ($order->status?->value ?? (string) $order->status) === OrderStatus::Cancelled->value) {
                $this->restaurantNotifications->notifyStatusChanged(
                    $order,
                    $previousStatus,
                    OrderStatus::Cancelled->value,
                    'customer'
                );
            }

            return;
        }

        if (in_array($previousStatus, [SmOrderStatus::Cancelled->value, SmOrderStatus::Completed->value], true)) {
            return;
        }

        $order = SmOrder::query()
            ->where('customer_id', $userId)
            ->with(['customer', 'store.owner'])
            ->find($orderId);

        if ($order && ($order->status?->value ?? (string) $order->status) === SmOrderStatus::Cancelled->value) {
            $this->supermarketNotifications->notifyStatusChanged(
                $order,
                $previousStatus,
                SmOrderStatus::Cancelled->value,
                'customer'
            );
        }
    }
}
