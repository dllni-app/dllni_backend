<?php

declare(strict_types=1);

use App\Filament\Resources\SystemAlerts\SystemAlertResource;
use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function (): void {
    app()->setLocale('ar');
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $adminRole = Role::findOrCreate('admin', 'web');
    $admin = User::factory()->create();
    $admin->assignRole($adminRole);

    $this->actingAs($admin);
});

it('hides system alerts from the dashboard navigation', function (): void {
    expect(SystemAlertResource::shouldRegisterNavigation())->toBeFalse();
});

it('shows the system managers page in Arabic with role instead of phone', function (): void {
    $role = Role::findOrCreate('Customer Support', 'web');
    $manager = User::factory()->create([
        'name' => 'مدير دعم تجريبي',
        'email' => 'support-manager@example.com',
        'phone' => '+963944123456',
    ]);
    $manager->assignRole($role);

    $this->get(UserResource::getUrl('index', [], isAbsolute: false))
        ->assertSuccessful()
        ->assertSee('مدراء النظام')
        ->assertSee('الاسم')
        ->assertSee('البريد الإلكتروني')
        ->assertSee('الدور')
        ->assertSee('دعم العملاء')
        ->assertDontSee('+963944123456');
});

it('translates the create system manager page and role options', function (): void {
    Role::findOrCreate('Cleaning Ops Manager', 'web');

    $this->get(UserResource::getUrl('create', [], isAbsolute: false))
        ->assertSuccessful()
        ->assertSee('إضافة مدير نظام')
        ->assertSee('الاسم')
        ->assertSee('البريد الإلكتروني')
        ->assertSee('رقم الهاتف')
        ->assertSee('الدور')
        ->assertSee('مدير عمليات التنظيف')
        ->assertSee('كلمة المرور');
});
