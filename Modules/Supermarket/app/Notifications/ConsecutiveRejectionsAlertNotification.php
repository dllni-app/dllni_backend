<?php

declare(strict_types=1);

namespace Modules\Supermarket\Notifications;

use Illuminate\Notifications\Notification;
use Modules\Supermarket\Models\SmStore;

final class ConsecutiveRejectionsAlertNotification extends Notification
{
    public function __construct(
        private SmStore $store,
        private int $recentCancelledCount,
    ) {}

    public function via(mixed $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(mixed $notifiable): array
    {
        return [
            'store_id' => $this->store->id,
            'store_name' => $this->store->name,
            'recent_cancellations' => $this->recentCancelledCount,
            'message' => "Alert: Your store has cancelled {$this->recentCancelledCount} consecutive orders. Please provide clarification.",
        ];
    }
}
