<?php

declare(strict_types=1);

namespace Modules\Supermarket\Notifications;

use App\Notifications\Core\NotificationPayloadBuilder;
use Illuminate\Notifications\Notification;
use Modules\Supermarket\Models\SmStore;

final class StoreTrustWarningNotification extends Notification
{
    private const string CanonicalType = 'supermarket.store.trust_warning';

    public function __construct(
        private readonly SmStore $store,
        private readonly int $newTrustScore,
    ) {}

    public function via(mixed $notifiable): array
    {
        return $this->payloadBuilder()->resolveChannels(self::CanonicalType, $notifiable);
    }

    public function toArray(mixed $notifiable): array
    {
        return $this->payloadBuilder()->makeDatabasePayload(
            canonicalType: self::CanonicalType,
            templateContext: [
                'trust_score' => $this->newTrustScore,
            ],
            extraData: [
                'store_id' => (int) $this->store->id,
                'store_name' => (string) $this->store->name,
                'trust_score' => $this->newTrustScore,
                'message' => "Your store's trust score has dropped to {$this->newTrustScore}. Please review recent order rejections.",
            ],
        );
    }

    private function payloadBuilder(): NotificationPayloadBuilder
    {
        return app(NotificationPayloadBuilder::class);
    }
}
