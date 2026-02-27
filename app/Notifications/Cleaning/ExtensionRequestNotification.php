<?php

declare(strict_types=1);

namespace App\Notifications\Cleaning;

use DevKandil\NotiFire\Enums\MessagePriority;
use DevKandil\NotiFire\FcmMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Modules\Cleaning\Models\CleaningTimeWarning;

final class ExtensionRequestNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly CleaningTimeWarning $timeWarning
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database', 'fcm'];
    }

    /**
     * @return array{type: string, title: string, body: string, bookingId: int|null, timeWarningId: int}
     */
    public function toArray(object $notifiable): array
    {
        $booking = $this->timeWarning->booking;

        return [
            'type' => 'extension_request',
            'title' => 'طلب تمديد وقت',
            'body' => 'العميل يطلب تمديد وقت الحجز. قم بقبول أو رفض الطلب.',
            'bookingId' => $booking ? (int) $booking->getKey() : null,
            'timeWarningId' => $this->timeWarning->id,
        ];
    }

    public function toFcm(object $notifiable): FcmMessage
    {
        $booking = $this->timeWarning->booking;
        $bookingId = $booking ? (int) $booking->getKey() : null;

        return FcmMessage::create(
            'طلب تمديد وقت',
            'العميل يطلب تمديد وقت الحجز. قم بقبول أو رفض الطلب.',
        )
            ->priority(MessagePriority::HIGH)
            ->data(array_filter([
                'type' => 'extension_request',
                'bookingId' => $bookingId,
                'timeWarningId' => $this->timeWarning->id,
            ], fn ($v) => $v !== null));
    }
}
