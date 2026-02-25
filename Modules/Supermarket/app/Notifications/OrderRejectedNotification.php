<?php

declare(strict_types=1);

namespace Modules\Supermarket\Notifications;

use Illuminate\Notifications\Notification;
use Modules\Supermarket\Models\SmOrder;

final class OrderRejectedNotification extends Notification
{
    public function __construct(
        private SmOrder $order,
        private string $reason,
    ) {}

    public function via(mixed $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(mixed $notifiable): array
    {
        return [
            'order_id' => $this->order->id,
            'order_number' => $this->order->order_number,
            'reason' => $this->reason,
            'message' => "Your order {$this->order->order_number} has been rejected.",
        ];
    }
}
