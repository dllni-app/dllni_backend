<?php

declare(strict_types=1);

use App\Services\Notifications\CachedFirebaseMessagingClient;
use DevKandil\NotiFire\Enums\MessagePriority;
use DevKandil\NotiFire\FcmMessage;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

it('reuses cached oauth token for multiple fcm sends', function (): void {
    Cache::put(config('notifications.fcm.oauth_cache_key'), 'cached-access-token', now()->addHour());

    config(['fcm.project_id' => 'test-project']);

    Http::fake([
        'fcm.googleapis.com/*' => Http::sequence()
            ->push(['name' => 'projects/test/messages/1'], 200)
            ->push(['name' => 'projects/test/messages/2'], 200),
    ]);

    $client = app(CachedFirebaseMessagingClient::class);
    $message = FcmMessage::create('Title', 'Body')->priority(MessagePriority::HIGH);

    $first = $client->sendToToken('token-a', $message);
    $second = $client->sendToToken('token-b', $message);

    expect($first->success)->toBeTrue()
        ->and($second->success)->toBeTrue();

    Http::assertSentCount(2);
});

it('refreshes the cached oauth token after an fcm auth failure', function (): void {
    Cache::put(config('notifications.fcm.oauth_cache_key'), 'cached-access-token', now()->addHour());

    config([
        'fcm.project_id' => 'test-project',
        'notifications.fcm.retry_times' => 0,
    ]);

    $resolverCalls = 0;

    Http::fake([
        'fcm.googleapis.com/*' => Http::sequence()
            ->push([
                'error' => [
                    'code' => 401,
                    'message' => 'Request had invalid authentication credentials.',
                    'status' => 'UNAUTHENTICATED',
                ],
            ], 401)
            ->push(['name' => 'projects/test/messages/retried'], 200),
    ]);

    $client = new CachedFirebaseMessagingClient(function () use (&$resolverCalls): array {
        $resolverCalls++;

        return [
            'access_token' => 'fresh-access-token',
            'expires_in' => 3600,
        ];
    });

    $message = FcmMessage::create('Title', 'Body')->priority(MessagePriority::HIGH);

    $result = $client->sendToToken('device-token', $message);

    expect($result->success)->toBeTrue()
        ->and($resolverCalls)->toBe(1)
        ->and(Cache::get(config('notifications.fcm.oauth_cache_key')))->toBe('fresh-access-token');

    Http::assertSentCount(2);

    Http::assertSent(function ($request): bool {
        return $request->hasHeader('Authorization', 'Bearer cached-access-token');
    });

    Http::assertSent(function ($request): bool {
        return $request->hasHeader('Authorization', 'Bearer fresh-access-token');
    });
});

it('builds high priority android and apns payload fields', function (): void {
    Http::fake([
        'fcm.googleapis.com/*' => Http::response(['name' => 'projects/test/messages/abc'], 200),
    ]);

    config(['fcm.project_id' => 'test-project']);

    Cache::put(config('notifications.fcm.oauth_cache_key'), 'test-token', now()->addHour());

    $client = app(CachedFirebaseMessagingClient::class);
    $message = FcmMessage::create('Offer', 'New delivery offer')
        ->priority(MessagePriority::HIGH)
        ->data([
            'canonical_type' => 'delivery.order.offer',
            'orderId' => '42',
        ]);

    $result = $client->sendToToken('device-token', $message);

    expect($result->success)->toBeTrue();

    Http::assertSent(function ($request): bool {
        $payload = $request->data();

        return ($payload['message']['android']['priority'] ?? null) === 'high'
            && ($payload['message']['apns']['headers']['apns-priority'] ?? null) === '10'
            && ($payload['message']['data']['canonical_type'] ?? null) === 'delivery.order.offer';
    });
});
