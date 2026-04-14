<?php

declare(strict_types=1);

namespace Modules\Supermarket\Notifications;

use DevKandil\NotiFire\Enums\MessagePriority;
use DevKandil\NotiFire\FcmMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

final class SmartListScheduledOrderSentNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly string $smartListName,
        private readonly string $orderNumber,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'fcm'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'smart_list_scheduled_order_sent',
            'title' => 'تم إرسال طلب القائمة الذكية',
            'body' => "تم إنشاء الطلب رقم {$this->orderNumber} من القائمة {$this->smartListName} وإرساله للمتجر.",
            'orderNumber' => $this->orderNumber,
        ];
    }

    public function toFcm(object $notifiable): FcmMessage
    {
        return FcmMessage::create(
            'تم إرسال طلب القائمة الذكية',
            "تم إنشاء الطلب رقم {$this->orderNumber} من القائمة {$this->smartListName} وإرساله للمتجر.",
        )
            ->priority(MessagePriority::HIGH)
            ->data([
                'type' => 'smart_list_scheduled_order_sent',
                'orderNumber' => $this->orderNumber,
            ]);
    }
}
