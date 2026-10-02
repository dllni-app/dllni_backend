<?php

declare(strict_types=1);

use App\Filament\Pages\PlatformOperationsDashboard;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Delivery\Models\DeliveryCompany;
use Modules\Delivery\Models\DeliveryOrder;
use Modules\Resturants\Models\Order;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

function platformDashboardUser(string $roleName): User
{
    $role = Role::findOrCreate($roleName, 'web');
    $user = User::factory()->create();
    $user->assignRole($role);

    return $user->fresh();
}

it('renders cross-domain attention sections for an admin', function (): void {
    $admin = platformDashboardUser('admin');
    $this->actingAs($admin);

    $restaurantOrder = Order::factory()->create([
        'order_number' => 'REST-DASH-LATE',
        'status' => 'preparing',
        'estimated_ready_at' => now()->subMinutes(20),
    ]);

    $restaurantOrder->forceFill([
        'accepted_at' => now()->subHour(),
        'preparing_at' => now()->subMinutes(50),
    ])->saveQuietly();

    $company = DeliveryCompany::factory()->create(['name' => 'Dashboard Delivery']);
    DeliveryOrder::factory()->create([
        'company_id' => $company->id,
        'order_number' => 'DEL-DASH-STOPPED',
        'status' => 'stopped',
        'stopped_at' => now(),
        'stop_reason' => 'No eligible drivers',
    ]);

    $this->get(PlatformOperationsDashboard::getUrl([], isAbsolute: false))
        ->assertSuccessful()
        ->assertSee('مركز عمليات المنصة')
        ->assertSee('المطاعم')
        ->assertSee('التوصيل')
        ->assertSee('REST-DASH-LATE')
        ->assertSee('DEL-DASH-STOPPED');
});

it('does not leak operational sections to a panel role without domain permissions', function (): void {
    $support = platformDashboardUser('Customer Support');
    $this->actingAs($support);

    $this->get(PlatformOperationsDashboard::getUrl([], isAbsolute: false))
        ->assertSuccessful()
        ->assertSee('لا توجد أقسام تشغيلية متاحة ضمن صلاحيات هذا الحساب.')
        ->assertDontSee('فتح قسم المطاعم')
        ->assertDontSee('فتح قسم السوبرماركت')
        ->assertDontSee('فتح قسم التوصيل');
});

it('shows only explicitly permitted operational domains to a non-admin role', function (): void {
    $support = platformDashboardUser('Customer Support');
    $support->givePermissionTo(Permission::findOrCreate('restaurant_orders.view', 'web'));
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->actingAs($support->fresh());

    $this->get(PlatformOperationsDashboard::getUrl([], isAbsolute: false))
        ->assertSuccessful()
        ->assertSee('المطاعم')
        ->assertSee('فتح قسم المطاعم')
        ->assertDontSee('فتح قسم السوبرماركت')
        ->assertDontSee('فتح قسم التوصيل');
});
