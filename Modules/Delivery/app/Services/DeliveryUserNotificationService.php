<?php

declare(strict_types=1);

namespace Modules\Delivery\Services;

use App\Models\User;
use Modules\Delivery\Models\DeliveryOrder;
use Modules\Delivery\Notifications\DeliveryUserNotification;

final class DeliveryUserNotificationService
{
    public function notifyAccepted(DeliveryOrder $order): void
    {
        $this->notify(
            order: $order,
            title: 'مندوبك في الطريق',
            body: 'تم قبول طلب التوصيل وسيصل المندوب إلى نقطة الاستلام قريباً.',
            event: 'accepted',
        );
    }

    public function notifyStarted(DeliveryOrder $order): void
    {
        $this->notify(
            order: $order,
            title: 'المندوب في الطريق للاستلام',
            body: 'بدأ المندوب عملية التوصيل وهو في الطريق إلى نقطة الاستلام.',
            event: 'started',
        );
    }

    public function notifyPickedUp(DeliveryOrder $order): void
    {
        $this->notify(
            order: $order,
            title: 'تم استلام الطلب',
            body: 'استلم المندوب طلبك وهو الآن في الطريق إليك.',
            event: 'picked_up',
        );
    }

    public function notifyDelivered(DeliveryOrder $order): void
    {
        $this->notify(
            order: $order,
            title: 'تم تسليم الطلب',
            body: 'تم تسليم طلبك بنجاح.',
            event: 'delivered',
        );
    }

    public function notifyCompleted(DeliveryOrder $order): void
    {
        $this->notify(
            order: $order,
            title: 'اكتمل طلب التوصيل',
            body: 'شكراً لاستخدامك دللني.',
            event: 'completed',
            priority: 'normal',
        );
    }

    public function notifyCancelled(DeliveryOrder $order, string $reason): void
    {
        $this->notify(
            order: $order,
            title: 'تم إلغاء التوصيل',
            body: 'تعذر إكمال طلب التوصيل. يرجى مراجعة تفاصيل الطلب.',
            event: 'cancelled',
            reason: $reason,
        );
    }

    public function notifyStopped(DeliveryOrder $order, string $reason): void
    {
        $this->notify(
            order: $order,
            title: 'توقف طلب التوصيل',
            body: 'حدثت مشكلة في طلب التوصيل. يرجى التواصل مع الدعم.',
            event: 'stopped',
            reason: $reason,
        );
    }

    private function notify(
        DeliveryOrder $order,
        string $title,
        string $body,
        string $event,
        ?string $reason = null,
        string $priority = 'high',
    ): void {
        $order->loadMissing('createdBy');

        if (! $order->createdBy instanceof User) {
            return;
        }

        $order->createdBy->notify(new DeliveryUserNotification(
            title: $title,
            body: $body,
            data: [
                'orderId' => $order->id,
                'order_id' => $order->id,
                'orderNumber' => $order->order_number,
                'order_number' => $order->order_number,
                'status' => $order->status,
                'event' => $event,
                'reason' => $reason,
                'deepLinkTarget' => 'delivery_order_tracking',
                'deep_link_target' => 'delivery_order_tracking',
            ],
            priority: $priority,
        ));
    }
}
