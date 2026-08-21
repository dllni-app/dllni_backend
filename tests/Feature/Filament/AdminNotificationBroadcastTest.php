<?php

declare(strict_types=1);

use App\Enums\UserModuleType;
use App\Filament\Resources\AdminNotificationBroadcasts\Pages\CreateAdminNotificationBroadcast;
use App\Jobs\DispatchAdminNotificationBroadcast;
use App\Models\AdminNotificationBroadcast;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function (): void {
    app()->setLocale('ar');
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $guardName = (string) config('auth.defaults.guard', 'web');
    Role::findOrCreate('admin', $guardName);

    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $this->actingAs($admin);
});

it('sends a broadcast notification to all users', function (): void {
    Queue::fake();

    Livewire::test(CreateAdminNotificationBroadcast::class)
        ->fillForm([
            'title' => 'صيانة النظام',
            'body' => 'سيتم إيقاف الخدمة مؤقتاً الليلة للصيانة.',
            'audience_type' => AdminNotificationBroadcast::AUDIENCE_ALL,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $broadcast = AdminNotificationBroadcast::query()->latest('id')->first();

    expect($broadcast)->not->toBeNull()
        ->and($broadcast->title)->toBe('صيانة النظام')
        ->and($broadcast->audience_type)->toBe(AdminNotificationBroadcast::AUDIENCE_ALL)
        ->and($broadcast->module_types)->toBeNull();

    Queue::assertPushed(DispatchAdminNotificationBroadcast::class, fn (DispatchAdminNotificationBroadcast $job): bool => $job->broadcastId === $broadcast->id);
});

it('requires at least one category when targeting module types', function (): void {
    Livewire::test(CreateAdminNotificationBroadcast::class)
        ->fillForm([
            'title' => 'عرض خاص',
            'body' => 'عرض خاص لهذه الفئة.',
            'audience_type' => AdminNotificationBroadcast::AUDIENCE_MODULE_TYPES,
            'module_types' => [],
        ])
        ->call('create')
        ->assertHasFormErrors(['module_types']);
});

it('sends a broadcast notification to a specific module type', function (): void {
    Queue::fake();

    Livewire::test(CreateAdminNotificationBroadcast::class)
        ->fillForm([
            'title' => 'إشعار للمطاعم',
            'body' => 'تحديث خاص بمطاعمكم.',
            'audience_type' => AdminNotificationBroadcast::AUDIENCE_MODULE_TYPES,
            'module_types' => [UserModuleType::RestaurantSeller->value],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $broadcast = AdminNotificationBroadcast::query()->latest('id')->first();

    expect($broadcast->module_types)->toBe([UserModuleType::RestaurantSeller->value]);
});

it('requires at least one user when targeting specific users', function (): void {
    Livewire::test(CreateAdminNotificationBroadcast::class)
        ->fillForm([
            'title' => 'إشعار مخصص',
            'body' => 'رسالة مخصصة.',
            'audience_type' => AdminNotificationBroadcast::AUDIENCE_SPECIFIC_USERS,
            'users' => [],
        ])
        ->call('create')
        ->assertHasFormErrors(['users']);
});

it('sends a broadcast notification to specific users and dispatches the delivery job', function (): void {
    Queue::fake();

    $recipient = User::factory()->create(['is_active' => true]);

    Livewire::test(CreateAdminNotificationBroadcast::class)
        ->fillForm([
            'title' => 'إشعار مخصص',
            'body' => 'رسالة مخصصة لك.',
            'audience_type' => AdminNotificationBroadcast::AUDIENCE_SPECIFIC_USERS,
            'users' => [$recipient->id],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $broadcast = AdminNotificationBroadcast::query()->latest('id')->first();

    expect($broadcast->users->pluck('id')->all())->toBe([$recipient->id]);
});

it('actually delivers the broadcast to matching users when the job runs', function (): void {
    $restaurantUser = User::factory()->create([
        'is_active' => true,
        'module_type' => UserModuleType::RestaurantSeller->value,
    ]);
    $regularUser = User::factory()->create([
        'is_active' => true,
        'module_type' => null,
    ]);

    $broadcast = AdminNotificationBroadcast::query()->create([
        'title' => 'إشعار للمطاعم',
        'body' => 'تحديث خاص بمطاعمكم.',
        'audience_type' => AdminNotificationBroadcast::AUDIENCE_MODULE_TYPES,
        'module_types' => [UserModuleType::RestaurantSeller->value],
    ]);

    DispatchAdminNotificationBroadcast::dispatchSync($broadcast->id);

    expect($restaurantUser->fresh()->notifications()->count())->toBe(1)
        ->and($regularUser->fresh()->notifications()->count())->toBe(0)
        ->and($broadcast->fresh()->recipients_count)->toBe(1)
        ->and($broadcast->fresh()->sent_at)->not->toBeNull();
});
