<?php

declare(strict_types=1);

namespace Modules\Supermarket\Notifications;

use App\Notifications\Concerns\UsesPushNotificationQueue;
use App\Notifications\Core\NotificationPayloadBuilder;
use DevKandil\NotiFire\FcmMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

final class SmartListScheduledOrderSentNotification extends Notification implements ShouldQueue
{
    use Queueable;
    use UsesPushNotificationQueue;
    private const string CanonicalType = 'supermarket.smart_list.scheduled_order_sent';

    public function __construct(
        private readonly string $smartListName,
        private readonly string $orderNumber,
    ) {
        $this->assignPushNotificationQueue();
    }

    public function via(object $notifiable): array
    {
        return $this->payloadBuilder()->resolveChannels(self::CanonicalType, $notifiable);
    }

    public function toArray(object $notifiable): array
    {
        return $this->payloadBuilder()->makeDatabasePayload(
            canonicalType: self::CanonicalType,
            templateContext: [
                'smart_list_name' => $this->smartListName,
                'order_number' => $this->orderNumber,
            ],
            extraData: [
                'orderNumber' => $this->orderNumber,
                'smartListName' => $this->smartListName,
            ],
        );
    }

    public function toFcm(object $notifiable): FcmMessage
    {
        return $this->payloadBuilder()->makeFcmMessage(
            canonicalType: self::CanonicalType,
            templateContext: [
                'smart_list_name' => $this->smartListName,
                'order_number' => $this->orderNumber,
            ],
            extraData: [
                'orderNumber' => $this->orderNumber,
                'smartListName' => $this->smartListName,
            ],
        );
    }

    private function payloadBuilder(): NotificationPayloadBuilder
    {
        return app(NotificationPayloadBuilder::class);
    }
}
