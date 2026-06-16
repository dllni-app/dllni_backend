<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use DevKandil\NotiFire\Enums\MessagePriority;
use DevKandil\NotiFire\FcmMessage;
use Exception;
use Google_Client;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

final class CachedFirebaseMessagingClient
{
    /**
     * @param  array<string, mixed>|null  $rawMessage
     */
    public function sendToToken(
        string $token,
        FcmMessage $message,
        ?object $notifiable = null,
        ?string $notificationClass = null,
        ?array $rawMessage = null,
    ): FcmSendResult {
        if ($token === '') {
            return new FcmSendResult(success: false);
        }

        $startedAt = microtime(true);
        $fields = $rawMessage ?? $this->buildMessagePayload($token, $message);
        $canonicalType = is_string($message->data['canonical_type'] ?? null)
            ? (string) $message->data['canonical_type']
            : null;

        try {
            $response = $this->callApi($fields);
            $durationMs = (int) round((microtime(true) - $startedAt) * 1000);
            $json = $response->json();
            $messageId = is_string($json['name'] ?? null) ? $json['name'] : null;

            if ($response->successful() && $messageId !== null) {
                $this->logPushEvent('info', 'FCM push sent', [
                    'duration_ms' => $durationMs,
                    'http_status' => $response->status(),
                    'message_id' => $messageId,
                    'canonical_type' => $canonicalType,
                    'notification_class' => $notificationClass,
                    'notifiable_id' => $this->notifiableId($notifiable),
                    'notifiable_type' => $notifiable !== null ? $notifiable::class : null,
                ]);

                return new FcmSendResult(
                    success: true,
                    httpStatus: $response->status(),
                    durationMs: $durationMs,
                    messageId: $messageId,
                );
            }

            $errorCode = $this->extractFcmErrorCode($json);
            $invalidToken = $this->isInvalidTokenError($errorCode, $response->status());

            $this->logPushEvent('error', 'FCM push failed', [
                'duration_ms' => $durationMs,
                'http_status' => $response->status(),
                'error_code' => $errorCode,
                'invalid_token' => $invalidToken,
                'response' => $response->body(),
                'canonical_type' => $canonicalType,
                'notification_class' => $notificationClass,
                'notifiable_id' => $this->notifiableId($notifiable),
            ]);

            return new FcmSendResult(
                success: false,
                invalidToken: $invalidToken,
                httpStatus: $response->status(),
                durationMs: $durationMs,
                errorCode: $errorCode,
            );
        } catch (Exception $exception) {
            $durationMs = (int) round((microtime(true) - $startedAt) * 1000);

            $this->logPushEvent('error', 'FCM push exception', [
                'duration_ms' => $durationMs,
                'error' => $exception->getMessage(),
                'canonical_type' => $canonicalType,
                'notification_class' => $notificationClass,
                'notifiable_id' => $this->notifiableId($notifiable),
            ]);

            return new FcmSendResult(
                success: false,
                durationMs: $durationMs,
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function buildMessagePayload(string $token, FcmMessage $message): array
    {
        $additionalData = collect($message->data ?? [])
            ->mapWithKeys(fn (mixed $value, mixed $key): array => [(string) $key => (string) $value])
            ->all();

        $payload = [
            'message' => [
                'notification' => [
                    'title' => $message->title,
                    'body' => $message->body,
                ],
                'android' => [
                    'priority' => $message->priority->value,
                    'notification' => array_filter([
                        'icon' => $message->icon,
                        'color' => $message->color,
                        'image' => $message->image,
                        'sound' => $message->sound ?? 'default',
                    ]),
                ],
                'apns' => [
                    'payload' => [
                        'aps' => array_filter([
                            'content-available' => 1,
                            'mutable-content' => 1,
                            'sound' => $message->sound ?? 'default',
                            'category' => $message->clickAction,
                        ]),
                    ],
                    'fcm_options' => array_filter([
                        'image' => $message->image,
                    ]),
                ],
                'webpush' => [
                    'notification' => array_filter([
                        'title' => $message->title,
                        'body' => $message->body,
                        'icon' => $message->icon,
                        'image' => $message->image,
                        'sound' => $message->sound ?? 'default',
                    ]),
                ],
                'token' => $token,
            ],
        ];

        if ($message->clickAction) {
            $payload['message']['android']['notification']['click_action'] = $message->clickAction;

            $parsedUrl = parse_url($message->clickAction);
            $webpushLink = $parsedUrl['path'] ?? $message->clickAction;
            $payload['message']['webpush']['fcm_options'] = [
                'link' => $webpushLink,
            ];
        }

        if ($additionalData !== []) {
            $payload['message']['data'] = $additionalData;
            $payload['message']['webpush']['data'] = $additionalData;
        }

        if ($message->priority === MessagePriority::HIGH) {
            $payload['message']['apns']['headers'] = [
                'apns-priority' => '10',
            ];
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    private function callApi(array $fields): \Illuminate\Http\Client\Response
    {
        $projectId = config('fcm.project_id');

        if (! is_string($projectId) || $projectId === '') {
            throw new Exception('Firebase project ID is not set. Check FIREBASE_PROJECT_ID.');
        }

        $apiUrl = config('fcm.api_url');

        if (! is_string($apiUrl) || $apiUrl === '') {
            $apiUrl = 'https://fcm.googleapis.com/v1/projects/'.$projectId.'/messages:send';
        }

        $accessToken = $this->getGoogleAccessToken();
        $retryTimes = (int) config('notifications.fcm.retry_times', 2);
        $retrySleepMs = (int) config('notifications.fcm.retry_sleep_ms', 100);

        return Http::withHeaders([
            'Authorization' => 'Bearer '.$accessToken,
            'Content-Type' => 'application/json',
        ])
            ->timeout((int) config('notifications.fcm.http_timeout', 10))
            ->connectTimeout((int) config('notifications.fcm.http_connect_timeout', 5))
            ->retry($retryTimes, $retrySleepMs, throw: false)
            ->post($apiUrl, $fields);
    }

    private function getGoogleAccessToken(): string
    {
        $cacheKey = (string) config('notifications.fcm.oauth_cache_key', 'fcm.google_access_token');

        /** @var string|null $cached */
        $cached = Cache::get($cacheKey);

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $credentialsFilePath = config('fcm.credentials_path');

        if (! is_string($credentialsFilePath) || ! file_exists($credentialsFilePath)) {
            throw new Exception('Firebase credentials file not found at: '.$credentialsFilePath);
        }

        $client = new Google_Client;
        $client->setAuthConfig($credentialsFilePath);
        $client->addScope('https://www.googleapis.com/auth/firebase.messaging');
        $client->refreshTokenWithAssertion();
        $token = $client->getAccessToken();

        if (! is_array($token) || ! is_string($token['access_token'] ?? null)) {
            throw new Exception('Failed to obtain Google access token for FCM.');
        }

        $expiresIn = (int) ($token['expires_in'] ?? 3600);
        $margin = (int) config('notifications.fcm.oauth_cache_ttl_margin', 300);
        $ttlSeconds = max(60, $expiresIn - $margin);

        Cache::put($cacheKey, $token['access_token'], now()->addSeconds($ttlSeconds));

        return $token['access_token'];
    }

    /**
     * @param  array<string, mixed>|null  $json
     */
    private function extractFcmErrorCode(?array $json): ?string
    {
        if (! is_array($json)) {
            return null;
        }

        $details = $json['error']['details'] ?? null;

        if (! is_array($details)) {
            return null;
        }

        foreach ($details as $detail) {
            if (! is_array($detail)) {
                continue;
            }

            $errorCode = $detail['errorCode'] ?? null;

            if (is_string($errorCode) && $errorCode !== '') {
                return $errorCode;
            }
        }

        return null;
    }

    private function isInvalidTokenError(?string $errorCode, int $httpStatus): bool
    {
        if (in_array($errorCode, ['UNREGISTERED', 'INVALID_ARGUMENT'], true)) {
            return true;
        }

        return $httpStatus === 404;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function logPushEvent(string $level, string $message, array $context = []): void
    {
        if (! config('notifications.fcm.logging_enabled', true)) {
            return;
        }

        Log::log($level, $message, $context);
    }

    private function notifiableId(?object $notifiable): int|string|null
    {
        if ($notifiable === null || ! method_exists($notifiable, 'getKey')) {
            return null;
        }

        return $notifiable->getKey();
    }
}
