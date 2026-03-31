<?php

declare(strict_types=1);

use App\Models\User;
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

it('requires authentication', function (): void {
    $this->getJson('/api/v1/user/notifications')->assertUnauthorized();
});
