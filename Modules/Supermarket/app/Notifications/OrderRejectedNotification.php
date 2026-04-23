<?php

declare(strict_types=1);

namespace Modules\Supermarket\Notifications;

use App\Notifications\Core\NotificationPayloadBuilder;
use Illuminate\Notifications\Notification;
use Modules\Supermarket\Models\SmOrder;

final class OrderRejectedNotification extends Notification
{
    private const string CanonicalType = 'supermarket.order.rejected';

    public function __construct(
        private readonly SmOrder $order,
        private readonly string $reason,
    ) {}

    public function via(mixed $notifiable): array
    {
        return $this->payloadBuilder()->resolveChannels(self::CanonicalType, $notifiable);
    }

    public function toArray(mixed $notifiable): array
    {
        return $this->payloadBuilder()->makeDatabasePayload(
            canonicalType: self::CanonicalType,
            templateContext: [
                'order_number' => (string) $this->order->order_number,
            ],
            extraData: [
                'order_id' => (int) $this->order->id,
                'order_number' => (string) $this->order->order_number,
                'reason' => $this->reason,
                'message' => "Your order {$this->order->order_number} has been rejected.",
            ],
        );
    }

    private function payloadBuilder(): NotificationPayloadBuilder
    {
        return app(NotificationPayloadBuilder::class);
    }
}
