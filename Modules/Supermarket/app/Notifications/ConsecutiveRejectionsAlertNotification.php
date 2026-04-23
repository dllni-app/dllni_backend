<?php

declare(strict_types=1);

namespace Modules\Supermarket\Notifications;

use App\Notifications\Core\NotificationPayloadBuilder;
use Illuminate\Notifications\Notification;
use Modules\Supermarket\Models\SmStore;

final class ConsecutiveRejectionsAlertNotification extends Notification
{
    private const string CanonicalType = 'supermarket.store.consecutive_rejections_alert';

    public function __construct(
        private readonly SmStore $store,
        private readonly int $recentCancelledCount,
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
                'recent_cancelled_count' => $this->recentCancelledCount,
            ],
            extraData: [
                'store_id' => (int) $this->store->id,
                'store_name' => (string) $this->store->name,
                'recent_cancellations' => $this->recentCancelledCount,
                'message' => "Alert: Your store has cancelled {$this->recentCancelledCount} consecutive orders. Please provide clarification.",
            ],
        );
    }

    private function payloadBuilder(): NotificationPayloadBuilder
    {
        return app(NotificationPayloadBuilder::class);
    }
}
