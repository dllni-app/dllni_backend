<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use Closure;
use DevKandil\NotiFire\Enums\MessagePriority;
use DevKandil\NotiFire\Exceptions\FcmRequestException;
use DevKandil\NotiFire\Exceptions\UnsupportedTokenFormat;
use DevKandil\NotiFire\FcmMessage;
use DevKandil\NotiFire\FcmService as PackageFcmService;
use Exception;
use Google_Client;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

final class CachedFcmService extends PackageFcmService
{
    private ?string $title = null;

    private ?string $body = null;

    private ?string $clickAction = null;

    private ?string $image = null;

    private ?string $icon = null;

    private ?string $color = null;

    private ?array $additionalData = null;

    private ?string $sound = null;

    private MessagePriority $priority;

    private ?array $fromArray = null;

    private ?string $authenticationKey = null;

    private ?array $fromRaw = null;

    public function __construct(
        private readonly ?Closure $accessTokenResolver = null,
    ) {
        $this->priority = MessagePriority::NORMAL;
    }

    public static function build(): static
    {
        return new static();
    }

    public function withTitle(string $title): static
    {
        $this->title = $title;

        return $this;
    }

    public function withBody(string $body): static
    {
        $this->body = $body;

        return $this;
    }

    public function withClickAction(?string $action): static
    {
        $this->clickAction = $action;

        return $this;
    }

    public function withImage(?string $image): static
    {
        $this->image = $image;

        return $this;
    }

    public function withIcon(?string $icon): static
    {
        $this->icon = $icon;

        return $this;
    }

    public function withColor(?string $color): static
    {
        $this->color = $color;

        return $this;
    }

    public function withSound(?string $sound): static
    {
        $this->sound = $sound;

        return $this;
    }

    public function withPriority(MessagePriority $priority): static
    {
        $this->priority = $priority;

        return $this;
    }

    public function withAdditionalData(array $data): static
    {
        $this->additionalData = collect($data)
            ->mapWithKeys(static fn (mixed $value, mixed $key): array => [(string) $key => (string) $value])
            ->all();

        return $this;
    }

    public function withAuthenticationKey(string $authenticationKey): static
    {
        $this->authenticationKey = $authenticationKey;

        return $this;
    }

    public function fromArray(array $fromArray): static
    {
        $this->fromArray = $fromArray;
        $this->fromRaw = $fromArray;

        return $this;
    }

    public function fromRaw(array $message): static
    {
        $this->fromRaw = $message;

        return $this;
    }

    public function sendNotification(mixed $token): bool
    {
        try {
            if (empty($token)) {
                $this->log('warning', 'Empty FCM token provided');

                return false;
            }

            if (! is_string($token) && ! is_array($token)) {
                throw new UnsupportedTokenFormat();
            }

            $tokens = is_string($token) ? [$token] : $token;
            $accessToken = $this->getGoogleAccessToken();
            $success = true;

            foreach ($tokens as $singleToken) {
                $fields = $this->buildTokenPayload((string) $singleToken);

                try {
                    $response = $this->callApi($fields, $accessToken);

                    if (isset($response['name'])) {
                        $this->log('info', 'FCM notification sent successfully', [
                            'token' => $singleToken,
                            'message_id' => $response['name'],
                        ]);
                    } else {
                        $this->log('error', 'Failed to send FCM notification', [
                            'token' => $singleToken,
                            'error' => $response['error'] ?? 'Unknown error',
                        ]);
                        $success = false;
                    }
                } catch (Exception $e) {
                    $this->log('error', 'FCM notification failed', [
                        'token' => $singleToken,
                        'error' => $e->getMessage(),
                    ]);

                    if (config('fcm.throw_exceptions')) {
                        throw $e;
                    }

                    $success = false;
                }
            }

            return $success;
        } catch (FcmRequestException $e) {
            $this->log('error', 'FCM notification failed', [
                'error' => $e->getMessage(),
                'response_data' => $e->getResponseData(),
                'trace' => $e->getTraceAsString(),
            ]);

            if (config('fcm.throw_exceptions')) {
                throw $e;
            }

            return false;
        } catch (Exception $e) {
            $this->log('error', 'FCM notification failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            if (config('fcm.throw_exceptions')) {
                throw $e;
            }

            return false;
        } finally {
            $this->resetState();
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function send(): array
    {
        try {
            $fields = $this->fromRaw ?? $this->fromArray ?? [];
            $response = $this->callApi($fields, $this->getGoogleAccessToken());

            if (isset($response['name'])) {
                $this->log('info', 'Raw FCM message sent successfully', [
                    'message_id' => $response['name'],
                ]);
            } else {
                $this->log('error', 'Failed to send raw FCM message', [
                    'error' => $response['error'] ?? 'Unknown error',
                ]);
            }

            return $response;
        } catch (FcmRequestException $e) {
            $this->log('error', 'Failed to send raw FCM message', [
                'error' => $e->getMessage(),
                'response_data' => $e->getResponseData(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        } catch (Exception $e) {
            $this->log('error', 'Failed to send raw FCM message', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        } finally {
            $this->resetState();
        }
    }

    public function sendToTopics(string|array $topics): bool
    {
        try {
            if (empty($topics)) {
                $this->log('warning', 'Empty topics provided');

                return false;
            }

            $accessToken = $this->getGoogleAccessToken();
            $fields = $this->buildTopicPayload($topics);
            $response = $this->callApi($fields, $accessToken);

            if (isset($response['name'])) {
                $this->log('info', 'FCM notification to topics sent successfully', [
                    'topics' => is_string($topics) ? $topics : implode(', ', $topics),
                    'message_id' => $response['name'],
                ]);

                return true;
            }

            $this->log('error', 'Failed to send FCM notification to topics', [
                'topics' => is_string($topics) ? $topics : implode(', ', $topics),
                'error' => $response['error'] ?? 'Unknown error',
            ]);

            return false;
        } catch (FcmRequestException $e) {
            $this->log('error', 'FCM notification to topics failed', [
                'error' => $e->getMessage(),
                'response_data' => $e->getResponseData(),
                'trace' => $e->getTraceAsString(),
            ]);

            if (config('fcm.throw_exceptions')) {
                throw $e;
            }

            return false;
        } catch (Exception $e) {
            $this->log('error', 'FCM notification to topics failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            if (config('fcm.throw_exceptions')) {
                throw $e;
            }

            return false;
        } finally {
            $this->resetState();
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function buildBasePayload(): array
    {
        $fields = [
            'message' => [
                'notification' => [
                    'title' => $this->title,
                    'body' => $this->body,
                ],
                'android' => [
                    'priority' => $this->priority->value,
                    'notification' => [
                        'icon' => $this->icon,
                        'color' => $this->color,
                        'image' => $this->image,
                        'sound' => $this->sound ?? 'default',
                    ],
                ],
                'apns' => [
                    'payload' => [
                        'aps' => [
                            'content-available' => 1,
                            'mutable-content' => 1,
                            'sound' => $this->sound ?? 'default',
                        ],
                    ],
                    'fcm_options' => [
                        'image' => $this->image,
                    ],
                ],
                'webpush' => [
                    'notification' => [
                        'title' => $this->title,
                        'body' => $this->body,
                        'icon' => $this->icon,
                        'image' => $this->image,
                        'sound' => $this->sound ?? 'default',
                    ],
                ],
            ],
        ];

        if ($this->clickAction !== null && $this->clickAction !== '') {
            $fields['message']['android']['notification']['click_action'] = $this->clickAction;
            $fields['message']['apns']['payload']['aps']['category'] = $this->clickAction;

            $parsedUrl = parse_url($this->clickAction);
            $webpushLink = is_array($parsedUrl) && isset($parsedUrl['path'])
                ? $parsedUrl['path']
                : $this->clickAction;

            $fields['message']['webpush']['fcm_options'] = [
                'link' => $webpushLink,
            ];
        }

        if ($this->additionalData) {
            $fields['message']['data'] = $this->additionalData;
            $fields['message']['webpush']['data'] = $this->additionalData;
        }

        return $fields;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildTokenPayload(string $token): array
    {
        $fields = $this->buildBasePayload();
        $fields['message']['token'] = $token;

        return $fields;
    }

    /**
     * @param  array<int, string>|string  $topics
     * @return array<string, mixed>
     */
    private function buildTopicPayload(array|string $topics): array
    {
        $fields = $this->buildBasePayload();

        if (is_string($topics)) {
            $fields['message']['topic'] = $topics;

            return $fields;
        }

        $conditions = array_map(
            static fn (string $topic): string => "'{$topic}' in topics",
            $topics,
        );

        $fields['message']['condition'] = implode(' || ', $conditions);

        return $fields;
    }

    /**
     * @param  array<string, mixed>  $fields
     * @return array<string, mixed>
     */
    private function callApi(array $fields, string $accessToken): array
    {
        $projectId = config('fcm.project_id');

        if (! is_string($projectId) || $projectId === '') {
            throw new Exception('Firebase project ID is not set. Please check your .env file for FIREBASE_PROJECT_ID');
        }

        $apiUrl = config('fcm.api_url');

        if (! is_string($apiUrl) || $apiUrl === '') {
            $apiUrl = 'https://fcm.googleapis.com/v1/projects/'.$projectId.'/messages:send';
        }

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer '.$accessToken,
                'Content-Type' => 'application/json',
            ])
                ->retry(3, 100)
                ->post($apiUrl, $fields);

            if (! $response->successful()) {
                throw new FcmRequestException(
                    'Failed to send FCM notification',
                    ['response' => $response->body(), 'status' => $response->status()]
                );
            }

            return $response->json();
        } catch (Exception $e) {
            if ($e instanceof FcmRequestException) {
                throw $e;
            }

            throw new FcmRequestException('Failed to send FCM notification: '.$e->getMessage());
        }
    }

    private function getGoogleAccessToken(bool $forceRefresh = false): string
    {
        if ($this->oauthCacheEnabled() && ! $forceRefresh) {
            /** @var string|null $cached */
            $cached = Cache::get($this->oauthCacheKey());

            if (is_string($cached) && $cached !== '') {
                return $cached;
            }
        }

        if ($this->oauthCacheEnabled() && $forceRefresh) {
            Cache::forget($this->oauthCacheKey());
        }

        $token = $this->fetchGoogleAccessToken();

        if (! is_array($token) || ! is_string($token['access_token'] ?? null)) {
            throw new Exception('Failed to obtain Google access token for FCM.');
        }

        if ($this->oauthCacheEnabled()) {
            $expiresIn = (int) ($token['expires_in'] ?? 3600);
            $margin = (int) config('notifications.fcm.oauth_cache_ttl_margin', 300);
            $ttlSeconds = max(60, $expiresIn - $margin);

            Cache::put($this->oauthCacheKey(), $token['access_token'], now()->addSeconds($ttlSeconds));
        }

        return $token['access_token'];
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchGoogleAccessToken(): array
    {
        if ($this->accessTokenResolver !== null) {
            $token = ($this->accessTokenResolver)();

            if (is_array($token)) {
                return $token;
            }

            throw new Exception('Custom FCM access token resolver must return an array payload.');
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

        return is_array($token) ? $token : [];
    }

    private function oauthCacheEnabled(): bool
    {
        return (bool) config('notifications.fcm.oauth_cache_enabled', true);
    }

    private function oauthCacheKey(): string
    {
        return (string) config('notifications.fcm.oauth_cache_key', 'fcm.google_access_token');
    }

    private function resetState(): void
    {
        $this->title = null;
        $this->body = null;
        $this->clickAction = null;
        $this->image = null;
        $this->icon = null;
        $this->color = null;
        $this->additionalData = null;
        $this->sound = null;
        $this->priority = MessagePriority::NORMAL;
        $this->fromArray = null;
        $this->authenticationKey = null;
        $this->fromRaw = null;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function log(string $level, string $message, array $context = []): void
    {
        if (config('fcm.logging_enabled', true)) {
            Log::log($level, $message, $context);
        }
    }
}
