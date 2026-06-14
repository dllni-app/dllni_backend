<?php

declare(strict_types=1);

namespace Modules\Delivery\Notifications;

use App\Notifications\Concerns\UsesPushNotificationQueue;
use App\Notifications\Core\NotificationPayloadBuilder;
use DevKandil\NotiFire\FcmMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

final class DeliveryCanonicalNotification extends Notification implements ShouldQueue
{
    use Queueable;
    use UsesPushNotificationQueue;

    /**
     * @param  array<string, scalar|null>  $templateContext
     * @param  array<string, mixed>  $extraData
     */
    public function __construct(
        private readonly string $canonicalType,
        private readonly array $templateContext = [],
        private readonly array $extraData = [],
    ) {
        $this->afterCommit();
        $this->assignPushNotificationQueue();
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return $this->payloadBuilder()->resolveChannels($this->canonicalType, $notifiable);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return $this->payloadBuilder()->makeDatabasePayload(
            canonicalType: $this->canonicalType,
            templateContext: $this->templateContext,
            extraData: $this->extraData,
        );
    }

    public function toFcm(object $notifiable): FcmMessage
    {
        return $this->payloadBuilder()->makeFcmMessage(
            canonicalType: $this->canonicalType,
            templateContext: $this->templateContext,
            extraData: $this->extraData,
        );
    }

    private function payloadBuilder(): NotificationPayloadBuilder
    {
        return app(NotificationPayloadBuilder::class);
    }
}
