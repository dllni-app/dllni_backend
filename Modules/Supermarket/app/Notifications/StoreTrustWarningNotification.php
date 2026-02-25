<?php

declare(strict_types=1);

namespace Modules\Supermarket\Notifications;

use Illuminate\Notifications\Notification;
use Modules\Supermarket\Models\SmStore;

final class StoreTrustWarningNotification extends Notification
{
    public function __construct(
        private SmStore $store,
        private int $newTrustScore,
    ) {}

    public function via(mixed $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(mixed $notifiable): array
    {
        return [
            'store_id' => $this->store->id,
            'store_name' => $this->store->name,
            'trust_score' => $this->newTrustScore,
            'message' => "Your store's trust score has dropped to {$this->newTrustScore}. Please review recent order rejections.",
        ];
    }
}
