<?php

declare(strict_types=1);

use App\Services\Notifications\CachedFcmService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

it('reuses the cached oauth token across multiple sends', function (): void {
    config([
        'fcm.project_id' => 'test-project',
        'fcm.logging_enabled' => false,
        'notifications.fcm.oauth_cache_enabled' => true,
        'notifications.fcm.oauth_cache_key' => 'tests.cached_fcm_access_token',
        'notifications.fcm.oauth_cache_ttl_margin' => 300,
    ]);

    $resolverCalls = 0;

    Http::fake([
        'fcm.googleapis.com/*' => Http::sequence()
            ->push(['name' => 'projects/test/messages/1'], 200)
            ->push(['name' => 'projects/test/messages/2'], 200),
    ]);

    $service = new CachedFcmService(function () use (&$resolverCalls): array {
        $resolverCalls++;

        return [
            'access_token' => 'fresh-access-token',
            'expires_in' => 3600,
        ];
    });

    $first = $service
        ->withTitle('Title')
        ->withBody('Body')
        ->sendNotification('token-a');

    $second = $service
        ->withTitle('Title')
        ->withBody('Body')
        ->sendNotification('token-b');

    expect($first)->toBeTrue()
        ->and($second)->toBeTrue()
        ->and($resolverCalls)->toBe(1)
        ->and(Cache::get('tests.cached_fcm_access_token'))->toBe('fresh-access-token');

    Http::assertSentCount(2);

    Http::assertSent(function (\Illuminate\Http\Client\Request $request): bool {
        return $request->hasHeader('Authorization', 'Bearer fresh-access-token');
    });
});
