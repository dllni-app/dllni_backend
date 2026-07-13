<?php

declare(strict_types=1);

use App\Filament\Resources\Roles\Pages\CreateRole;
use App\Filament\Resources\Roles\RoleResource;
use App\Filament\Resources\Roles\Schemas\RoleForm;
use App\Filament\Support\ArabicDashboardLabels;
use App\Models\User;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
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

it('creates dashboard roles with the web guard without exposing the guard field', function (): void {
    Permission::findOrCreate('pricing.view', 'web');

    $this->get(RoleResource::getUrl('create', [], isAbsolute: false))
        ->assertSuccessful()
        ->assertDontSee('نطاق الصلاحية');

    Livewire::test(CreateRole::class)
        ->fillForm([
            'name' => 'مدير اختبار',
            RoleForm::permissionFieldFor('pricing.view') => ['pricing.view'],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $role = Role::query()->where('name', 'مدير اختبار')->firstOrFail();

    expect($role->guard_name)->toBe('web')
        ->and($role->hasPermissionTo('pricing.view', 'web'))->toBeTrue();
});

it('renders role view and edit pages without passing grouped option arrays as labels', function (): void {
    $permission = Permission::findOrCreate('pricing.view', 'web');
    $role = Role::findOrCreate('Pricing Reviewer', 'web');
    $role->syncPermissions([$permission]);

    $this->get(RoleResource::getUrl('view', ['record' => $role], isAbsolute: false))
        ->assertSuccessful()
        ->assertSee('عرض التسعير');

    $this->get(RoleResource::getUrl('edit', ['record' => $role], isAbsolute: false))
        ->assertSuccessful()
        ->assertSee('التسعير')
        ->assertSee('عرض التسعير');
});

it('shows Arabic permission labels grouped by translated sections', function (): void {
    Permission::findOrCreate('pricing.view', 'web');
    Permission::findOrCreate('pricing.create', 'web');
    Permission::query()->updateOrCreate(
        ['name' => 'ro.orders', 'guard_name' => 'web'],
        [
            'slug' => 'إدارة طلبات المطعم',
            'description' => 'إدارة الطلبات الواردة إلى المطعم',
            'group' => 'restaurant_owner',
        ],
    );

    Livewire::test(CreateRole::class)
        ->assertSee('التسعير')
        ->assertSee('عرض التسعير')
        ->assertSee('إنشاء التسعير')
        ->assertSee('إدارة المطاعم')
        ->assertSee('إدارة طلبات المطعم');

    expect(ArabicDashboardLabels::permissionName('pricing.create'))->toBe('إنشاء التسعير')
        ->and(ArabicDashboardLabels::permissionSectionName('pricing.create'))->toBe('التسعير')
        ->and(ArabicDashboardLabels::permissionSectionName('ro.orders', 'restaurant_owner'))->toBe('إدارة المطاعم');
});
