<?php

declare(strict_types=1);

namespace App\Notifications\Channels;

use App\Models\User;
use App\Services\Notifications\CachedFirebaseMessagingClient;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use Throwable;

final class CachedFcmChannel
{
    public function __construct(
        private readonly CachedFirebaseMessagingClient $messagingClient,
    ) {}

    public function send(mixed $notifiable, Notification $notification): void
    {
        if (! method_exists($notification, 'toFcm')) {
            Log::warning('Notification does not have toFcm method', [
                'notification' => $notification::class,
            ]);

            return;
        }

        $fcmToken = $notifiable->routeNotificationFor('fcm', $notification);

        if (! is_string($fcmToken) || $fcmToken === '') {
            Log::warning('No FCM token found for notifiable', [
                'notifiable_id' => method_exists($notifiable, 'getKey') ? $notifiable->getKey() : null,
                'notifiable_type' => is_object($notifiable) ? $notifiable::class : null,
            ]);

            return;
        }

        $message = $notification->toFcm($notifiable);

        try {
            if (is_array($message)) {
                $result = $this->messagingClient->sendToToken(
                    token: $fcmToken,
                    message: \DevKandil\NotiFire\FcmMessage::create('', ''),
                    notifiable: $notifiable,
                    notificationClass: $notification::class,
                    rawMessage: $message,
                );
            } else {
                $result = $this->messagingClient->sendToToken(
                    token: $fcmToken,
                    message: $message,
                    notifiable: $notifiable,
                    notificationClass: $notification::class,
                );
            }

            if ($result->invalidToken) {
                $this->clearInvalidFcmToken($notifiable, $fcmToken);
            }
        } catch (Throwable $exception) {
            Log::error('Failed to send FCM notification through cached channel', [
                'error' => $exception->getMessage(),
                'notification' => $notification::class,
                'notifiable_id' => method_exists($notifiable, 'getKey') ? $notifiable->getKey() : null,
            ]);
        }
    }

    private function clearInvalidFcmToken(mixed $notifiable, string $token): void
    {
        if (! $notifiable instanceof User) {
            return;
        }

        if ($notifiable->fcm_token !== $token) {
            return;
        }

        $notifiable->forceFill(['fcm_token' => null])->saveQuietly();

        Log::info('Cleared invalid FCM token for user', [
            'user_id' => $notifiable->getKey(),
        ]);
    }
}
