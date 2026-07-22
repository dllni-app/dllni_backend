<?php

declare(strict_types=1);

use App\Filament\Resources\SystemUsers\SystemUserResource;
use App\Models\User;
use App\Services\UserAccountStatusService;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function (): void {
    app()->setLocale('ar');
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $adminRole = Role::findOrCreate('admin', 'web');
    $admin = User::factory()->create([
        'is_active' => true,
    ]);
    $admin->assignRole($adminRole);

    $this->actingAs($admin);
});

it('shows application users without duplicating system managers', function (): void {
    $customer = User::factory()->create([
        'name' => 'مستخدم تجريبي',
        'email' => 'customer@example.com',
        'phone' => '+963944555111',
        'is_active' => true,
    ]);

    $managerRole = Role::findOrCreate('Customer Support', 'web');
    $manager = User::factory()->create([
        'name' => 'مدير دعم',
        'email' => 'manager@example.com',
        'is_active' => true,
    ]);
    $manager->assignRole($managerRole);

    $this->get(SystemUserResource::getUrl('index', [], isAbsolute: false))
        ->assertSuccessful()
        ->assertSee('مستخدمو النظام')
        ->assertSee($customer->name)
        ->assertSee($customer->phone)
        ->assertSee('فعال')
        ->assertDontSee($manager->email);
});

it('deactivates a user and revokes all api tokens', function (): void {
    $user = User::factory()->create([
        'is_active' => true,
    ]);
    $user->createToken('test-token');

    app(UserAccountStatusService::class)->deactivate($user);

    expect($user->refresh()->is_active)->toBeFalse()
        ->and($user->tokens()->count())->toBe(0);
});

it('reactivates a deactivated user', function (): void {
    $user = User::factory()->create([
        'is_active' => false,
    ]);

    app(UserAccountStatusService::class)->activate($user);

    expect($user->refresh()->is_active)->toBeTrue();
});
