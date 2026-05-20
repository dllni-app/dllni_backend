<?php

declare(strict_types=1);

namespace App\Notifications\Cleaning;

use App\Notifications\Core\NotificationPayloadBuilder;
use DevKandil\NotiFire\FcmMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Modules\Cleaning\Models\CleaningBooking;

final class NewOrderRequestNotification extends Notification implements ShouldQueue
{
    use Queueable;
    private const string CanonicalType = 'cleaning.booking.new_order_request';

    public function __construct(
        private readonly CleaningBooking $booking
    ) {
        $this->onQueue('notifications');
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
     * @return array{type: string, title: string, body: string, bookingId: int}
     */
    public function toArray(object $notifiable): array
    {
        return $this->payloadBuilder()->makeDatabasePayload(
            canonicalType: self::CanonicalType,
            templateContext: [
                'booking_number' => (string) $this->booking->booking_number,
            ],
            extraData: [
                'bookingId' => (int) $this->booking->id,
                'orderId' => (int) $this->booking->id,
                'status' => (string) $this->booking->status->value,
                'action' => 'new_order_request',
                'deep_link_target' => 'cleaning_booking_details',
                'occurred_at' => $this->booking->created_at?->toIso8601String() ?? now()->toIso8601String(),
            ],
        );
    }

    public function toFcm(object $notifiable): FcmMessage
    {
        return $this->payloadBuilder()->makeFcmMessage(
            canonicalType: self::CanonicalType,
            templateContext: [
                'booking_number' => (string) $this->booking->booking_number,
            ],
            extraData: [
                'bookingId' => (int) $this->booking->id,
                'orderId' => (int) $this->booking->id,
                'status' => (string) $this->booking->status->value,
                'action' => 'new_order_request',
                'deep_link_target' => 'cleaning_booking_details',
                'occurred_at' => $this->booking->created_at?->toIso8601String() ?? now()->toIso8601String(),
            ],
        );
    }

    private function payloadBuilder(): NotificationPayloadBuilder
    {
        return app(NotificationPayloadBuilder::class);
    }
}
