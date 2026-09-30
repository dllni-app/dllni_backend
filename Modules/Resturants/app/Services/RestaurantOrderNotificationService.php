<?php

declare(strict_types=1);

namespace Modules\Resturants\Services;

use App\Models\User;
use Illuminate\Support\Facades\Log;
use Modules\Resturants\Models\Order;
use Modules\Resturants\Notifications\RestaurantOrderLifecycleNotification;
use Throwable;

final class RestaurantOrderNotificationService
{
    public function notifyCreated(Order $order): void
    {
        $order->loadMissing(['user', 'restaurant.user', 'restaurant.staff.user']);

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

        $this->notifyRestaurantRecipients(
            $order,
            event: 'created',
            fromStatus: null,
            toStatus: $this->statusValue($order),
            actorRole: 'customer',
        );
    }

    public function notifyStatusChanged(Order $order, ?string $fromStatus, string $toStatus, string $actorRole = 'system'): void
    {
        if ($fromStatus === $toStatus) {
            return;
        }

        $order->loadMissing(['user', 'restaurant.user', 'restaurant.staff.user']);

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
            $this->notifyRestaurantRecipients(
                $order,
                event: 'status_changed',
                fromStatus: $fromStatus,
                toStatus: $toStatus,
                actorRole: $actorRole,
            );
        }
    }

    private function notifyRestaurantRecipients(
        Order $order,
        string $event,
        ?string $fromStatus,
        string $toStatus,
        string $actorRole,
    ): void {
        $restaurant = $order->restaurant;
        if ($restaurant === null) {
            return;
        }

        $owner = $restaurant->user;
        $recipients = collect();

        if ($owner instanceof User) {
            $recipients->push($owner);
        }

        foreach ($restaurant->staff->where('is_active', true) as $staff) {
            $staffUser = $staff->user;
            if (! $staffUser instanceof User) {
                continue;
            }

            if (! $staffUser->getAllPermissions()->pluck('name')->contains('ro.orders')) {
                continue;
            }

            $recipients->push($staffUser);
        }

        foreach ($recipients->unique('id') as $recipient) {
            $this->notifySafely(
                $recipient,
                new RestaurantOrderLifecycleNotification(
                    order: $order,
                    targetRole: 'owner',
                    event: $event,
                    fromStatus: $fromStatus,
                    toStatus: $toStatus,
                    actorRole: $actorRole,
                ),
                'restaurant owner/staff order notification'
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
