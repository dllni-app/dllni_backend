<?php

declare(strict_types=1);

namespace App\Notifications\Cleaning;

use App\Notifications\Core\NotificationPayloadBuilder;
use DevKandil\NotiFire\FcmMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Modules\Cleaning\Models\CleaningBooking;

final class BookingLifecycleNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly CleaningBooking $booking,
        private readonly string $canonicalType,
        private readonly string $actorRole,
        private readonly string $targetRole,
        private readonly ?string $fromStatus = null,
        private readonly ?string $action = null,
        private readonly ?string $deepLinkTarget = null,
        private readonly ?string $occurredAt = null,
    ) {
        $this->afterCommit();
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return $this->payloadBuilder()->resolveChannels($this->canonicalType, $notifiable);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return $this->payloadBuilder()->makeDatabasePayload(
            canonicalType: $this->canonicalType,
            templateContext: $this->templateContext(),
            extraData: $this->extraData(),
        );
    }

    public function toFcm(object $notifiable): FcmMessage
    {
        return $this->payloadBuilder()->makeFcmMessage(
            canonicalType: $this->canonicalType,
            templateContext: $this->templateContext(),
            extraData: $this->extraData(),
        );
    }

    /**
     * @return array<string, scalar|null>
     */
    private function templateContext(): array
    {
        return [
            'booking_number' => (string) $this->booking->booking_number,
            'status' => (string) $this->booking->status->value,
            'from_status' => $this->fromStatus,
            'action' => $this->action,
            'actor_role' => $this->actorRole,
            'target_role' => $this->targetRole,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function extraData(): array
    {
        $resolvedAction = $this->action ?? str_replace('cleaning.booking.', '', $this->canonicalType);
        $resolvedDeepLinkTarget = $this->deepLinkTarget ?? ($this->targetRole === 'worker'
            ? 'cleaning_booking_details'
            : 'cleaning_order_details');

        return array_filter([
            'bookingId' => (int) $this->booking->id,
            'orderId' => (int) $this->booking->id,
            'status' => (string) $this->booking->status->value,
            'action' => $resolvedAction,
            'deep_link_target' => $resolvedDeepLinkTarget,
            'occurred_at' => $this->occurredAt ?? now()->toIso8601String(),
            'fromStatus' => $this->fromStatus,
            'actorRole' => $this->actorRole,
            'targetRole' => $this->targetRole,
            'bookingNumber' => (string) $this->booking->booking_number,
        ], fn (mixed $value): bool => $value !== null);
    }

    private function payloadBuilder(): NotificationPayloadBuilder
    {
        return app(NotificationPayloadBuilder::class);
    }
}
