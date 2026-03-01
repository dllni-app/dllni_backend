<?php

declare(strict_types=1);

namespace App\Notifications\Cleaning;

use App\Models\Dispute;
use DevKandil\NotiFire\Enums\MessagePriority;
use DevKandil\NotiFire\FcmMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

final class DisputeOpenedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly Dispute $dispute
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database', 'fcm'];
    }

    /**
     * @return array{type: string, title: string, body: string, bookingId: int|null, disputeId: int}
     */
    public function toArray(object $notifiable): array
    {
        $booking = $this->dispute->booking;

        return [
            'type' => 'dispute_opened',
            'title' => 'نزاع مفتوح',
            'body' => 'تم فتح نزاع على إحدى حجوزاتك. يرجى الرد على الشكوى.',
            'bookingId' => $booking ? (int) $booking->getKey() : null,
            'disputeId' => $this->dispute->id,
        ];
    }

    public function toFcm(object $notifiable): FcmMessage
    {
        $booking = $this->dispute->booking;
        $bookingId = $booking ? (int) $booking->getKey() : null;

        return FcmMessage::create(
            'نزاع مفتوح',
            'تم فتح نزاع على إحدى حجوزاتك. يرجى الرد على الشكوى.',
        )
            ->priority(MessagePriority::HIGH)
            ->data(array_filter([
                'type' => 'dispute_opened',
                'bookingId' => $bookingId,
                'disputeId' => $this->dispute->id,
            ], fn ($v) => $v !== null));
    }
}
