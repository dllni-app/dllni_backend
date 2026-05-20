<?php

declare(strict_types=1);

namespace App\Notifications\Cleaning;

use App\Models\Dispute;
use App\Notifications\Core\NotificationPayloadBuilder;
use DevKandil\NotiFire\FcmMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

final class DisputeOpenedNotification extends Notification implements ShouldQueue
{
    use Queueable;
    private const string CanonicalType = 'cleaning.booking.dispute_opened';

    public function __construct(
        private readonly Dispute $dispute
    ) {
        $this->afterCommit();
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return $this->payloadBuilder()->resolveChannels(self::CanonicalType, $notifiable);
    }

    /**
     * @return array{type: string, title: string, body: string, bookingId: int|null, disputeId: int}
     */
    public function toArray(object $notifiable): array
    {
        $booking = $this->dispute->booking;

        return $this->payloadBuilder()->makeDatabasePayload(
            canonicalType: self::CanonicalType,
            extraData: array_filter([
                'bookingId' => $booking ? (int) $booking->getKey() : null,
                'orderId' => $booking ? (int) $booking->getKey() : null,
                'status' => $booking?->status?->value,
                'action' => 'dispute_opened',
                'deep_link_target' => 'cleaning_booking_details',
                'occurred_at' => $this->dispute->created_at?->toIso8601String() ?? now()->toIso8601String(),
                'disputeId' => (int) $this->dispute->id,
            ], fn (mixed $value): bool => $value !== null),
        );
    }

    public function toFcm(object $notifiable): FcmMessage
    {
        $booking = $this->dispute->booking;

        return $this->payloadBuilder()->makeFcmMessage(
            canonicalType: self::CanonicalType,
            extraData: array_filter([
                'bookingId' => $booking ? (int) $booking->getKey() : null,
                'orderId' => $booking ? (int) $booking->getKey() : null,
                'status' => $booking?->status?->value,
                'action' => 'dispute_opened',
                'deep_link_target' => 'cleaning_booking_details',
                'occurred_at' => $this->dispute->created_at?->toIso8601String() ?? now()->toIso8601String(),
                'disputeId' => $this->dispute->id,
            ], fn (mixed $value): bool => $value !== null),
        );
    }

    private function payloadBuilder(): NotificationPayloadBuilder
    {
        return app(NotificationPayloadBuilder::class);
    }
}
