<?php

declare(strict_types=1);

namespace Modules\Supermarket\Services;

use Illuminate\Support\Facades\Log;
use Modules\Supermarket\Models\SmOrder;
use Modules\Supermarket\Notifications\SmOrderLifecycleNotification;
use Throwable;

final class SmOrderNotificationService
{
    public function notifyCreated(SmOrder $order): void
    {
        $order->loadMissing(['customer', 'store.owner']);

        $this->notifySafely(
            $order->customer,
            new SmOrderLifecycleNotification(
                order: $order,
                targetRole: 'customer',
                event: 'created',
                toStatus: $this->statusValue($order),
                actorRole: 'customer',
            ),
            'supermarket customer order-created notification'
        );

        $this->notifySafely(
            $order->store?->owner,
            new SmOrderLifecycleNotification(
                order: $order,
                targetRole: 'owner',
                event: 'created',
                toStatus: $this->statusValue($order),
                actorRole: 'customer',
            ),
            'supermarket owner order-created notification'
        );
    }

    public function notifyStatusChanged(SmOrder $order, ?string $fromStatus, string $toStatus, string $actorRole = 'system'): void
    {
        if ($fromStatus === $toStatus) {
            return;
        }

        $order->loadMissing(['customer', 'store.owner']);

        $this->notifySafely(
            $order->customer,
            new SmOrderLifecycleNotification(
                order: $order,
                targetRole: 'customer',
                event: 'status_changed',
                fromStatus: $fromStatus,
                toStatus: $toStatus,
                actorRole: $actorRole,
            ),
            'supermarket customer order-status notification'
        );

        if ($actorRole !== 'owner') {
            $this->notifySafely(
                $order->store?->owner,
                new SmOrderLifecycleNotification(
                    order: $order,
                    targetRole: 'owner',
                    event: 'status_changed',
                    fromStatus: $fromStatus,
                    toStatus: $toStatus,
                    actorRole: $actorRole,
                ),
                'supermarket owner order-status notification'
            );
        }
    }

    private function statusValue(SmOrder $order): string
    {
        return $order->status?->value ?? (string) $order->status;
    }

    private function notifySafely(mixed $notifiable, SmOrderLifecycleNotification $notification, string $context): void
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
