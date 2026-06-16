<?php

declare(strict_types=1);

namespace App\Notifications\Concerns;

trait UsesPushNotificationQueue
{
    protected function assignPushNotificationQueue(): void
    {
        $queue = config('notifications.push_queue');

        if (is_string($queue) && $queue !== '') {
            $this->onQueue($queue);
        }
    }
}
