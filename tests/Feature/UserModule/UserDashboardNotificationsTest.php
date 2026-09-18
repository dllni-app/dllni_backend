<?php

declare(strict_types=1);

use App\Models\User;
use App\Notifications\UserAccountDashboardNotification;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function (): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    Role::findOrCreate('admin', 'web');
});

it('notifies dashboard admins when a user registers from the app endpoint', function (): void {
    Queue::fake();

    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $this->postJson('/api/v1/user/register', [
        'name' => 'مستخدم جديد',
        'phone' => '+963944008811',
        'password' => 'password123',
    ])->assertOk();

    $notification = $admin->fresh()->notifications()
        ->where('type', UserAccountDashboardNotification::class)
        ->latest()
        ->first();

    expect($notification)->not->toBeNull()
        ->and(data_get($notification?->data, 'title'))->toBe('تم إنشاء حساب مستخدم جديد');
});

it('notifies dashboard admins when an app user changes their name', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $user = User::factory()->create(['name' => 'الاسم القديم']);
    Sanctum::actingAs($user);

    $this->patchJson('/api/v1/user/account', ['name' => 'الاسم الجديد'])
        ->assertOk()
        ->assertJsonPath('user.name', 'الاسم الجديد');

    $notification = $admin->fresh()->notifications()
        ->where('type', UserAccountDashboardNotification::class)
        ->latest()
        ->first();

    expect($notification)->not->toBeNull()
        ->and(data_get($notification?->data, 'title'))->toBe('قام مستخدم بتغيير اسمه')
        ->and(data_get($notification?->data, 'body'))->toContain('الاسم القديم')
        ->and(data_get($notification?->data, 'body'))->toContain('الاسم الجديد');
});
