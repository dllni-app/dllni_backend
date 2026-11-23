<?php

declare(strict_types=1);

namespace Modules\Cleaning\Notifications;

use App\Notifications\Core\NotificationPayloadBuilder;
use Illuminate\Notifications\Notification;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingSession;
use Ramsey\Uuid\Uuid;

/**
 * A durable in-app (database) notification for one V3 shortage outbox intent.
 * FCM/push remains a separate transport and is not claimed as delivered here.
 */
final class CleaningV3VisitShortageNotification extends Notification
{
    public function __construct(
        private readonly CleaningBooking $booking,
        private readonly CleaningBookingSession $visit,
        private readonly int $remainingWorkers,
        private readonly string $recipientKind,
        int $intentId,
        int $recipientUserId,
    ) {
        // Stable across retries, process restarts, and duplicate schedulers.
        // Laravel's notifications.id is a UUID primary key.
        $this->id = Uuid::uuid5(
            Uuid::NAMESPACE_URL,
            'alnadha:cleaning:v3:shortage:'.$intentId.':'.$recipientUserId,
        )->toString();
    }

    /** @return array<int,string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string,mixed> */
    public function toArray(object $notifiable): array
    {
        $type = $this->recipientKind === 'admin'
            ? 'cleaning.v3.visit.shortage_admin'
            : 'cleaning.v3.visit.shortage_customer';

        return app(NotificationPayloadBuilder::class)->makeDatabasePayload(
            canonicalType: $type,
            templateContext: [
                'booking_number' => (string) $this->booking->booking_number,
                'sequence' => (string) $this->visit->sequence,
                'remaining_workers' => (string) $this->remainingWorkers,
            ],
            extraData: [
                'notificationId' => $this->id,
                'notification_id' => $this->id,
                'bookingId' => (int) $this->booking->id,
                'sessionId' => (int) $this->visit->id,
                'action' => 'v3_visit_search_escalated',
                'remainingWorkers' => $this->remainingWorkers,
                'searchExpired' => false,
                'retryOrCancel' => false,
                'deep_link_target' => $this->recipientKind === 'customer'
                    ? 'cleaning_order_details' : null,
            ],
        );
    }
}
