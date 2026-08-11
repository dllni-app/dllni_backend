<?php

declare(strict_types=1);

namespace Modules\Resturants\Services;

use Illuminate\Support\Facades\Log;
use Modules\Resturants\Models\Order;
use Modules\Resturants\Notifications\RestaurantOrderLifecycleNotification;
use Throwable;

final class RestaurantOrderNotificationService
{
    public function notifyCreated(Order $order): void
    {
        $order->loadMissing(['user', 'restaurant.user']);

        $this->notifySafely(
            $order->user,
            new RestaurantOrderLifecycleNotification(
                order: $order,
                targetRole: 'customer',
                event: 'created',
                toStatus: $this->statusValue($order),
                actorRole: 'customer',
            ),
            'restaurant customer order-created notification'
        );

        $this->notifySafely(
            $order->restaurant?->user,
            new RestaurantOrderLifecycleNotification(
                order: $order,
                targetRole: 'owner',
                event: 'created',
                toStatus: $this->statusValue($order),
                actorRole: 'customer',
            ),
            'restaurant owner order-created notification'
        );
    }

    public function notifyStatusChanged(Order $order, ?string $fromStatus, string $toStatus, string $actorRole = 'system'): void
    {
        if ($fromStatus === $toStatus) {
            return;
        }

        $order->loadMissing(['user', 'restaurant.user']);

        $this->notifySafely(
            $order->user,
            new RestaurantOrderLifecycleNotification(
                order: $order,
                targetRole: 'customer',
                event: 'status_changed',
                fromStatus: $fromStatus,
                toStatus: $toStatus,
                actorRole: $actorRole,
            ),
            'restaurant customer order-status notification'
        );

        if ($actorRole !== 'owner') {
            $this->notifySafely(
                $order->restaurant?->user,
                new RestaurantOrderLifecycleNotification(
                    order: $order,
                    targetRole: 'owner',
                    event: 'status_changed',
                    fromStatus: $fromStatus,
                    toStatus: $toStatus,
                    actorRole: $actorRole,
                ),
                'restaurant owner order-status notification'
            );
        }
    }

    private function statusValue(Order $order): string
    {
        return $order->status?->value ?? (string) $order->status;
    }

    private function notifySafely(mixed $notifiable, RestaurantOrderLifecycleNotification $notification, string $context): void
    {
        if (! is_object($notifiable) || ! method_exists($notifiable, 'notify')) {
            return;
        }

        try {
            $notifiable->notify($notification);
        } catch (Throwable $exception) {
            Log::warning("Failed to send {$context}: {$exception->getMessage()}", [
                'order_id' => (int) $notification->toArray($notifiable)['orderId'],
                'exception' => $exception,
            ]);
        }
    }
}
