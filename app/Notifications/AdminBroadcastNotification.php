<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\AdminNotificationBroadcast;
use DevKandil\NotiFire\Enums\MessagePriority;
use DevKandil\NotiFire\FcmMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

final class AdminBroadcastNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly AdminNotificationBroadcast $broadcast)
    {
        $this->afterCommit();
    }

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        $channels = ['database'];

        $fcmToken = is_callable([$notifiable, 'routeNotificationForFcm'])
            ? $notifiable->routeNotificationForFcm($this)
            : null;

        if (is_string($fcmToken) && $fcmToken !== '') {
            $channels[] = 'fcm';
        }

        return $channels;
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'admin.broadcast',
            'canonical_type' => 'admin.broadcast',
            'category' => 'system',
            'priority' => 'normal',
            'title' => $this->broadcast->title,
            'body' => $this->broadcast->body,
            'message' => $this->broadcast->body,
            'data' => [
                'broadcastId' => $this->broadcast->id,
            ],
        ];
    }

    public function toFcm(object $notifiable): FcmMessage
    {
        return FcmMessage::create($this->broadcast->title, $this->broadcast->body)
            ->priority(MessagePriority::NORMAL)
            ->data([
                'type' => 'admin.broadcast',
                'canonical_type' => 'admin.broadcast',
                'broadcastId' => (string) $this->broadcast->id,
            ]);
    }
}
