<?php

declare(strict_types=1);

namespace Modules\Supermarket\Notifications;

use DevKandil\NotiFire\Enums\MessagePriority;
use DevKandil\NotiFire\FcmMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

final class SmartListScheduledOrderFailedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly string $smartListName,
        private readonly string $reason,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'fcm'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'smart_list_scheduled_order_failed',
            'title' => 'فشل تنفيذ الطلب المجدول',
            'body' => "تعذر إرسال طلب القائمة {$this->smartListName}. السبب: {$this->reason}",
            'reason' => $this->reason,
        ];
    }

    public function toFcm(object $notifiable): FcmMessage
    {
        return FcmMessage::create(
            'فشل تنفيذ الطلب المجدول',
            "تعذر إرسال طلب القائمة {$this->smartListName}. السبب: {$this->reason}",
        )
            ->priority(MessagePriority::HIGH)
            ->data([
                'type' => 'smart_list_scheduled_order_failed',
                'reason' => $this->reason,
            ]);
    }
}
