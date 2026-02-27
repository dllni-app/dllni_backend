<?php

declare(strict_types=1);

namespace App\Notifications\Cleaning;

use DevKandil\NotiFire\Enums\MessagePriority;
use DevKandil\NotiFire\FcmMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Modules\Cleaning\Models\CleaningBooking;

final class NewOrderRequestNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly CleaningBooking $booking
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database', 'fcm'];
    }

    /**
     * @return array{type: string, title: string, body: string, bookingId: int}
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'new_order',
            'title' => 'طلب جديد',
            'body' => "طلب تنظيف جديد: {$this->booking->booking_number}. قم بقبوله أو رفضه خلال الوقت المحدد.",
            'bookingId' => $this->booking->id,
        ];
    }

    public function toFcm(object $notifiable): FcmMessage
    {
        return FcmMessage::create(
            'طلب جديد',
            "طلب تنظيف جديد: {$this->booking->booking_number}. قم بقبوله أو رفضه خلال الوقت المحدد.",
        )
            ->priority(MessagePriority::HIGH)
            ->data([
                'type' => 'new_order',
                'bookingId' => $this->booking->id,
            ]);
    }
}
