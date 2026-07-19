<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Worker;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

it('returns paginated notifications for the app user module', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $user->notify(new class extends Notification
    {
        public function via(object $notifiable): array
        {
            return ['database'];
        }

        /**
         * @return array<string, mixed>
         */
        public function toArray(object $notifiable): array
        {
            return [
                'type' => 'order',
                'title' => 'Order update',
                'body' => 'Your order is ready.',
            ];
        }
    });

    $response = $this->getJson('/api/v1/user/notifications?perPage=5');

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(1);
    expect($response->json('data.0.title'))->toBe('Order update');
    expect($response->json('data.0.body'))->toBe('Your order is ready.');
});

it('filters notifications to unread only', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $databaseType = 'Illuminate\\Notifications\\DatabaseNotification';

    $user->notifications()->create([
        'id' => (string) Str::uuid(),
        'type' => $databaseType,
        'data' => ['title' => 'Unread A', 'body' => ''],
        'read_at' => null,
    ]);
    $user->notifications()->create([
        'id' => (string) Str::uuid(),
        'type' => $databaseType,
        'data' => ['title' => 'Read B', 'body' => ''],
        'read_at' => now(),
    ]);

    $response = $this->getJson('/api/v1/user/notifications?filter[unread]=1');

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(1);
    expect($response->json('data.0.title'))->toBe('Unread A');
});

it('marks a notification as read', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $databaseType = 'Illuminate\\Notifications\\DatabaseNotification';

    $notification = $user->notifications()->create([
        'id' => (string) Str::uuid(),
        'type' => $databaseType,
        'data' => ['title' => 'Unread A', 'body' => ''],
        'read_at' => null,
    ]);

    $this->patchJson("/api/v1/user/notifications/{$notification->id}/read")
        ->assertNoContent();

    expect($notification->fresh()->read_at)->not->toBeNull();
});

it('requires authentication to mark a notification as read', function (): void {
    $this->patchJson('/api/v1/user/notifications/'.Str::uuid().'/read')
        ->assertUnauthorized();
});

it('marks all notifications as read', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $databaseType = 'Illuminate\\Notifications\\DatabaseNotification';

    $user->notifications()->create([
        'id' => (string) Str::uuid(),
        'type' => $databaseType,
        'data' => ['title' => 'Unread A', 'body' => ''],
        'read_at' => null,
    ]);
    $user->notifications()->create([
        'id' => (string) Str::uuid(),
        'type' => $databaseType,
        'data' => ['title' => 'Unread B', 'body' => ''],
        'read_at' => null,
    ]);

    $this->patchJson('/api/v1/user/notifications/read-all')
        ->assertNoContent();

    expect($user->fresh()->unreadNotifications()->count())->toBe(0);
});

it('requires authentication to mark all notifications as read', function (): void {
    $this->patchJson('/api/v1/user/notifications/read-all')
        ->assertUnauthorized();
});

it('deletes a notification', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $databaseType = 'Illuminate\\Notifications\\DatabaseNotification';

    $notification = $user->notifications()->create([
        'id' => (string) Str::uuid(),
        'type' => $databaseType,
        'data' => ['title' => 'Unread A', 'body' => ''],
        'read_at' => null,
    ]);

    $this->deleteJson("/api/v1/user/notifications/{$notification->id}")
        ->assertNoContent();

    expect($user->fresh()->notifications()->where('id', $notification->id)->exists())->toBeFalse();
});

it('does not delete another users notification', function (): void {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    Sanctum::actingAs($other);

    $databaseType = 'Illuminate\\Notifications\\DatabaseNotification';

    $notification = $owner->notifications()->create([
        'id' => (string) Str::uuid(),
        'type' => $databaseType,
        'data' => ['title' => 'Owned', 'body' => ''],
        'read_at' => null,
    ]);

    $this->deleteJson("/api/v1/user/notifications/{$notification->id}")
        ->assertNotFound();

    expect($owner->fresh()->notifications()->where('id', $notification->id)->exists())->toBeTrue();
});

it('requires authentication to delete a notification', function (): void {
    $this->deleteJson('/api/v1/user/notifications/'.Str::uuid())
        ->assertUnauthorized();
});

it('deletes all notifications', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $databaseType = 'Illuminate\\Notifications\\DatabaseNotification';

    $user->notifications()->create([
        'id' => (string) Str::uuid(),
        'type' => $databaseType,
        'data' => ['title' => 'Unread A', 'body' => ''],
        'read_at' => null,
    ]);
    $user->notifications()->create([
        'id' => (string) Str::uuid(),
        'type' => $databaseType,
        'data' => ['title' => 'Read B', 'body' => ''],
        'read_at' => now(),
    ]);

    $this->deleteJson('/api/v1/user/notifications/all')
        ->assertNoContent();

    expect($user->fresh()->notifications()->count())->toBe(0);
    expect($user->fresh()->unreadNotifications()->count())->toBe(0);
});

it('requires authentication to delete all notifications', function (): void {
    $this->deleteJson('/api/v1/user/notifications/all')
        ->assertUnauthorized();
});

it('requires authentication', function (): void {
    $this->getJson('/api/v1/user/notifications')->assertUnauthorized();
});

it('returns notification module for cleaning notifications', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $user->notifications()->create([
        'id' => (string) Str::uuid(),
        'type' => 'App\\Notifications\\Cleaning\\NewOrderRequestNotification',
        'data' => [
            'type' => 'new_order',
            'title' => 'Order update',
            'body' => 'Your order is ready.',
        ],
        'read_at' => null,
    ]);

    $response = $this->getJson('/api/v1/user/notifications');

    $response->assertOk();
    expect($response->json('data.0.module'))->toBe('cleaning');
    expect($response->json('data.0.icon'))->toBe(url('/images/notifications/cleaning.svg'));
});

it('returns notification module from payload override', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $user->notifications()->create([
        'id' => (string) Str::uuid(),
        'type' => 'Illuminate\\Notifications\\DatabaseNotification',
        'data' => [
            'module' => 'supermarket',
            'type' => 'order_update',
            'title' => 'Order update',
            'body' => 'Your order is being prepared.',
        ],
        'read_at' => null,
    ]);

    $response = $this->getJson('/api/v1/user/notifications');

    $response->assertOk();
    expect($response->json('data.0.module'))->toBe('supermarket');
    expect($response->json('data.0.icon'))->toBe(url('/images/notifications/supermarket.svg'));
});

it('returns payload icon when provided', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $iconUrl = 'https://cdn.example.com/icons/custom-notification.png';

    $user->notifications()->create([
        'id' => (string) Str::uuid(),
        'type' => 'Illuminate\\Notifications\\DatabaseNotification',
        'data' => [
            'module' => 'restaurant',
            'type' => 'order_update',
            'icon' => $iconUrl,
            'title' => 'Order update',
            'body' => 'Order moved to preparing.',
        ],
        'read_at' => null,
    ]);

    $response = $this->getJson('/api/v1/user/notifications');

    $response->assertOk();
    expect($response->json('data.0.icon'))->toBe($iconUrl);
});

it('normalizes notification routing data for get notifications response', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $user->notifications()->create([
        'id' => (string) Str::uuid(),
        'type' => 'Illuminate\\Notifications\\DatabaseNotification',
        'data' => [
            'type' => 'worker_started_travel',
            'canonical_type' => 'cleaning.booking.worker_started_travel',
            'title' => 'Worker is on the way',
            'body' => 'The worker started travel for booking CB-1001.',
            'bookingId' => 1001,
            'orderId' => 1001,
            'action' => 'worker_started_travel',
            'status' => 'worker_assigned',
            'deep_link_target' => 'cleaning_order_details',
        ],
        'read_at' => null,
    ]);

    $response = $this->getJson('/api/v1/user/notifications');

    $response->assertOk();
    expect($response->json('data.0.data.deep_link_target'))->toBe('cleaning_order_details');
    expect($response->json('data.0.data.deepLinkTarget'))->toBe('cleaning_order_details');
    expect($response->json('data.0.data.args'))->toBeJson();
    expect(json_decode((string) $response->json('data.0.data.args'), true))->toMatchArray([
        'route' => 'cleaning_order_details',
        'bookingId' => 1001,
        'orderId' => 1001,
        'action' => 'worker_started_travel',
        'status' => 'worker_assigned',
    ]);
});

it('registers fcm token for user notifications endpoint using alias keys', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $response = $this->putJson('/api/v1/user/notifications/token', [
        'device_token' => 'fcm_test_token_1234567890',
    ]);

    $response->assertOk();
    expect($response->json('data.tokenRegistered'))->toBeTrue();
    expect($user->fresh()->fcm_token)->toBe('fcm_test_token_1234567890');
});

it('syncs fcm token from request header on authenticated requests', function (): void {
    $user = User::factory()->create([
        'fcm_token' => null,
    ]);
    Sanctum::actingAs($user);

    $response = $this->withHeader('fcm-token', 'header_sync_fcm_token_1234567890')
        ->getJson('/api/v1/user/notifications');

    $response->assertOk();
    expect($user->fresh()->fcm_token)->toBe('header_sync_fcm_token_1234567890');
});

it('registers fcm token for user notifications endpoint using fcm-token header', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $response = $this->withHeaders([
        'fcm-token' => 'fcm_header_token_1234567890',
    ])->putJson('/api/v1/user/notifications/token', []);

    $response->assertOk();
    expect($response->json('data.tokenRegistered'))->toBeTrue();
    expect($user->fresh()->fcm_token)->toBe('fcm_header_token_1234567890');
});

it('registers fcm token for cleaning worker notifications endpoint', function (): void {
    $workerUser = User::factory()->create();
    Worker::factory()->create(['user_id' => $workerUser->id]);
    Sanctum::actingAs($workerUser);

    $response = $this->putJson('/api/v1/cleaning/worker/account/notifications/token', [
        'fcmToken' => 'worker_fcm_token_1234567890',
    ]);

    $response->assertOk();
    expect($response->json('data.tokenRegistered'))->toBeTrue();
    expect($workerUser->fresh()->fcm_token)->toBe('worker_fcm_token_1234567890');
});

it('marks all worker notification feed items as read', function (): void {
    $workerUser = User::factory()->create();
    Worker::factory()->create(['user_id' => $workerUser->id]);
    Sanctum::actingAs($workerUser);

    $workerUser->notifications()->create([
        'id' => (string) Str::uuid(),
        'type' => 'Illuminate\\Notifications\\DatabaseNotification',
        'data' => ['title' => 'Unread', 'body' => ''],
        'read_at' => null,
    ]);

    $this->patchJson('/api/v1/cleaning/worker/account/notifications/read-all')
        ->assertNoContent();

    expect($workerUser->fresh()->unreadNotifications()->count())->toBe(0);
});
