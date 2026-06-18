<?php

declare(strict_types=1);

use App\Models\User;
use DevKandil\NotiFire\Enums\MessagePriority;
use DevKandil\NotiFire\FcmMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

it('sends a firebase push notification through the package fcm route', function (): void {
    config([
        'fcm.project_id' => 'test-project',
        'fcm.logging_enabled' => false,
        'notifications.fcm.logging_enabled' => false,
        'notifications.fcm.oauth_cache_key' => 'tests.firebase_push_access_token',
    ]);

    Cache::put('tests.firebase_push_access_token', 'cached-access-token', now()->addHour());

    Http::fake([
        'fcm.googleapis.com/*' => Http::response([
            'name' => 'projects/test/messages/firebase-browser-token-smoke',
        ], 200),
    ]);

    $user = User::factory()->create([
        'fcm_token' => 'browser_push_token_1234567890',
    ]);

    $notification = new class extends Notification
    {
        public function via(object $notifiable): array
        {
            return ['fcm'];
        }

        public function toFcm(object $notifiable): FcmMessage
        {
            return FcmMessage::create('Push smoke test', 'Firebase push delivery reached the HTTP client.')
                ->priority(MessagePriority::HIGH)
                ->data([
                    'canonical_type' => 'qa.firebase.push_smoke_test',
                    'source' => 'pest',
                ]);
        }
    };

    $user->notify($notification);

    Http::assertSent(function (\Illuminate\Http\Client\Request $request): bool {
        $payload = $request->data();

        return $request->hasHeader('Authorization', 'Bearer cached-access-token')
            && ($payload['message']['token'] ?? null) === 'browser_push_token_1234567890'
            && ($payload['message']['notification']['title'] ?? null) === 'Push smoke test'
            && ($payload['message']['data']['canonical_type'] ?? null) === 'qa.firebase.push_smoke_test'
            && ($payload['message']['data']['source'] ?? null) === 'pest'
            && ($payload['message']['android']['priority'] ?? null) === 'high'
            && ! array_key_exists('headers', $payload['message']['apns'] ?? []);
    });
});
