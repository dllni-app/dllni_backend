<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

use function Pest\Laravel\getJson;
use function Pest\Laravel\patchJson;

it('tracks notification delivery when the authenticated feed is loaded', function (): void {
    $user = User::factory()->create(['is_active' => true]);
    $notificationId = (string) Str::uuid();

    $user->notifications()->create([
        'id' => $notificationId,
        'type' => 'Tests\\Fixtures\\NotificationState',
        'data' => [
            'type' => 'notification_state_test',
            'module' => 'cleaning',
            'title' => 'State test',
            'body' => 'Notification state test',
            'message' => 'Notification state test',
        ],
        'read_at' => null,
        'delivered_at' => null,
        'viewed_at' => null,
    ]);

    Sanctum::actingAs($user);

    $response = getJson('/api/v1/user/notifications')->assertOk();

    expect($response->json('data.0.id'))->toBe($notificationId)
        ->and($response->json('data.0.deliveredAt'))->not->toBeNull()
        ->and($response->json('data.0.viewedAt'))->toBeNull()
        ->and($response->json('data.0.readAt'))->toBeNull();

    $stored = $user->notifications()->where('id', $notificationId)->firstOrFail();
    expect($stored->getAttribute('delivered_at'))->not->toBeNull()
        ->and($stored->getAttribute('viewed_at'))->toBeNull()
        ->and($stored->read_at)->toBeNull();
});

it('keeps the legacy read endpoint compatible while recording a viewed notification', function (): void {
    $user = User::factory()->create(['is_active' => true]);
    $notificationId = (string) Str::uuid();

    $user->notifications()->create([
        'id' => $notificationId,
        'type' => 'Tests\\Fixtures\\NotificationState',
        'data' => [
            'type' => 'notification_state_test',
            'module' => 'cleaning',
            'title' => 'State test',
            'body' => 'Notification state test',
            'message' => 'Notification state test',
        ],
        'read_at' => null,
        'delivered_at' => null,
        'viewed_at' => null,
    ]);

    Sanctum::actingAs($user);

    patchJson("/api/v1/user/notifications/{$notificationId}/read")
        ->assertNoContent();

    $stored = $user->notifications()->where('id', $notificationId)->firstOrFail();
    expect($stored->getAttribute('delivered_at'))->not->toBeNull()
        ->and($stored->getAttribute('viewed_at'))->not->toBeNull()
        ->and($stored->read_at)->not->toBeNull();
});
