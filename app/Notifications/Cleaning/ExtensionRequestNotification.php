<?php

declare(strict_types=1);

namespace App\Notifications\Cleaning;

use App\Notifications\Core\NotificationPayloadBuilder;
use DevKandil\NotiFire\FcmMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Modules\Cleaning\Models\CleaningTimeWarning;

final class ExtensionRequestNotification extends Notification implements ShouldQueue
{
    use Queueable;

    private const string CanonicalType = 'cleaning.booking.extension_request';

    public function __construct(
        private readonly CleaningTimeWarning $timeWarning
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
     * @return array{type: string, title: string, body: string, bookingId: int|null, timeWarningId: int}
     */
    public function toArray(object $notifiable): array
    {
        $booking = $this->timeWarning->booking;

        return $this->payloadBuilder()->makeDatabasePayload(
            canonicalType: self::CanonicalType,
            extraData: array_filter([
                'bookingId' => $booking ? (int) $booking->getKey() : null,
                'orderId' => $booking ? (int) $booking->getKey() : null,
                'status' => $booking?->status?->value,
                'action' => 'extension_request',
                'deep_link_target' => 'cleaning_booking_details',
                'occurred_at' => $this->timeWarning->created_at?->toIso8601String() ?? now()->toIso8601String(),
                'timeWarningId' => (int) $this->timeWarning->id,
            ], fn (mixed $value): bool => $value !== null),
        );
    }

    public function toFcm(object $notifiable): FcmMessage
    {
        $booking = $this->timeWarning->booking;

        return $this->payloadBuilder()->makeFcmMessage(
            canonicalType: self::CanonicalType,
            extraData: array_filter([
                'bookingId' => $booking ? (int) $booking->getKey() : null,
                'orderId' => $booking ? (int) $booking->getKey() : null,
                'status' => $booking?->status?->value,
                'action' => 'extension_request',
                'deep_link_target' => 'cleaning_booking_details',
                'occurred_at' => $this->timeWarning->created_at?->toIso8601String() ?? now()->toIso8601String(),
                'timeWarningId' => $this->timeWarning->id,
            ], fn (mixed $value): bool => $value !== null),
        );
    }

    private function payloadBuilder(): NotificationPayloadBuilder
    {
        return app(NotificationPayloadBuilder::class);
    }
}
