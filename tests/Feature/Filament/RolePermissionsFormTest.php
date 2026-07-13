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

it('renders role permissions under the four operational dashboard sections', function (): void {
    $restaurantPermission = Permission::query()->updateOrCreate(
        ['name' => 'ro.orders', 'guard_name' => 'web'],
        [
            'slug' => 'إدارة طلبات المطعم',
            'group' => 'restaurant_owner',
        ],
    );
    $storePermission = Permission::findOrCreate('stores.view', 'web');
    $cleaningPermission = Permission::findOrCreate('cleaning_bookings.view', 'web');
    $deliveryPermission = Permission::findOrCreate('delivery_orders.view', 'web');

    $role = Role::findOrCreate('Operations Reviewer', 'web');
    $role->syncPermissions([
        $restaurantPermission,
        $storePermission,
        $cleaningPermission,
        $deliveryPermission,
    ]);

    $this->get(RoleResource::getUrl('view', ['record' => $role], isAbsolute: false))
        ->assertSuccessful()
        ->assertSee('بيانات الدور')
        ->assertSee('قسم المطاعم')
        ->assertSee('إدارة طلبات المطعم')
        ->assertSee('قسم المتاجر')
        ->assertSee('عرض المتاجر')
        ->assertSee('عمليات التنظيف')
        ->assertSee('عرض حجوزات التنظيف')
        ->assertSee('التوصيل')
        ->assertSee('عرض طلبات التوصيل');

    $this->get(RoleResource::getUrl('edit', ['record' => $role], isAbsolute: false))
        ->assertSuccessful()
        ->assertSee('قسم المطاعم')
        ->assertSee('قسم المتاجر')
        ->assertSee('عمليات التنظيف')
        ->assertSee('التوصيل');
});

it('shows Arabic permission labels grouped by translated resource sections', function (): void {
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
        ->assertSee('عمليات التنظيف')
        ->assertSee('التسعير')
        ->assertSee('عرض التسعير')
        ->assertSee('إنشاء التسعير')
        ->assertSee('قسم المطاعم')
        ->assertSee('إدارة المطاعم')
        ->assertSee('إدارة طلبات المطعم');

    expect(ArabicDashboardLabels::permissionName('pricing.create'))->toBe('إنشاء التسعير')
        ->and(ArabicDashboardLabels::permissionSectionName('pricing.create'))->toBe('التسعير')
        ->and(ArabicDashboardLabels::permissionMainSectionName('pricing.create'))->toBe('عمليات التنظيف')
        ->and(ArabicDashboardLabels::permissionSectionName('ro.orders', 'restaurant_owner'))->toBe('إدارة المطاعم')
        ->and(ArabicDashboardLabels::permissionMainSectionName('ro.orders', 'restaurant_owner'))->toBe('قسم المطاعم');
});
