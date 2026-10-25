<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;

it('exposes notifications only to the authenticated supervisor and marks them as read', function (): void {
    Role::findOrCreate('Cleaning Supervisor', 'web');
    $supervisor = User::factory()->create(['is_active' => true]);
    $supervisor->assignRole('Cleaning Supervisor');
    $other = User::factory()->create(['is_active' => true]);

    $notification = $supervisor->notifications()->create([
        'id' => (string) Str::uuid(),
        'type' => 'Illuminate\\Notifications\\DatabaseNotification',
        'data' => ['module' => 'cleaning', 'type' => 'booking_update', 'title' => 'اختبار', 'body' => 'إشعار للمشرف'],
        'read_at' => null,
    ]);
    $otherNotification = $other->notifications()->create([
        'id' => (string) Str::uuid(),
        'type' => 'Illuminate\\Notifications\\DatabaseNotification',
        'data' => ['module' => 'cleaning', 'title' => 'سري', 'body' => 'غير متاح للمشرف'],
        'read_at' => null,
    ]);

    $this->getJson('/api/v1/supervisor/notifications')->assertUnauthorized();
    Sanctum::actingAs($other);
    $this->getJson('/api/v1/supervisor/notifications')->assertForbidden();
    Sanctum::actingAs($supervisor);

    $this->getJson('/api/v1/supervisor/notifications')
        ->assertOk()
        ->assertJsonPath('countUnread', 1)
        ->assertJsonPath('data.0.id', $notification->id)
        ->assertJsonPath('data.0.title', 'اختبار')
        ->assertJsonMissing(['id' => $otherNotification->id]);

    $this->patchJson('/api/v1/supervisor/notifications/'.$otherNotification->id.'/read')
        ->assertNotFound();
    $this->patchJson('/api/v1/supervisor/notifications/read-all')->assertNoContent();
    $this->assertNotNull($notification->fresh()->read_at);
    $this->assertNull($otherNotification->fresh()->read_at);
    $this->getJson('/api/v1/supervisor/notifications')->assertJsonPath('countUnread', 0);
});
