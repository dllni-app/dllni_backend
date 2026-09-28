<?php

declare(strict_types=1);

use App\Filament\Pages\DeliveryOperationsHub;
use App\Filament\Resources\DeliveryDrivers\DeliveryDriverResource;
use App\Filament\Resources\DeliveryOrders\DeliveryOrderResource;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Delivery\Models\DeliveryCompany;
use Modules\Delivery\Models\DeliveryDriver;
use Modules\Delivery\Models\DeliveryOrder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

function adminDeliveryUser(string $roleName = 'admin'): User
{
    $role = Role::findOrCreate($roleName, 'web');
    $user = User::factory()->create();
    $user->assignRole($role);

    return $user->fresh();
}

it('shows platform delivery operations across companies to an admin', function (): void {
    $admin = adminDeliveryUser();
    $this->actingAs($admin);

    $companyA = DeliveryCompany::factory()->create(['name' => 'Alpha Delivery']);
    $companyB = DeliveryCompany::factory()->create(['name' => 'Beta Delivery']);

    $driverA = DeliveryDriver::factory()->create([
        'company_id' => $companyA->id,
        'first_name' => 'Driver Alpha',
    ]);
    $driverB = DeliveryDriver::factory()->create([
        'company_id' => $companyB->id,
        'first_name' => 'Driver Beta',
    ]);

    $orderA = DeliveryOrder::factory()->create([
        'company_id' => $companyA->id,
        'driver_id' => $driverA->id,
        'order_number' => 'ADMIN-DEL-A',
        'status' => 'accepted',
    ]);
    $orderB = DeliveryOrder::factory()->create([
        'company_id' => $companyB->id,
        'driver_id' => $driverB->id,
        'order_number' => 'ADMIN-DEL-B',
        'status' => 'stopped',
        'stopped_at' => now(),
        'stop_reason' => 'No drivers available',
    ]);

    $this->get(DeliveryOperationsHub::getUrl([], isAbsolute: false))
        ->assertSuccessful()
        ->assertSee('مركز عمليات التوصيل')
        ->assertSee('ADMIN-DEL-B');

    $this->get(DeliveryOrderResource::getUrl('index', [], isAbsolute: false))
        ->assertSuccessful()
        ->assertSee('ADMIN-DEL-A')
        ->assertSee('ADMIN-DEL-B')
        ->assertSee('Alpha Delivery')
        ->assertSee('Beta Delivery');

    $this->get(DeliveryOrderResource::getUrl('view', ['record' => $orderA], isAbsolute: false))
        ->assertSuccessful()
        ->assertSee('ADMIN-DEL-A')
        ->assertSee('Driver Alpha');

    $this->get(DeliveryDriverResource::getUrl('index', [], isAbsolute: false))
        ->assertSuccessful()
        ->assertSee('Driver Alpha')
        ->assertSee('Driver Beta');
});

it('keeps platform delivery operations permission-gated for non-admin panel roles', function (): void {
    $support = adminDeliveryUser('Customer Support');
    $this->actingAs($support);

    $this->get(DeliveryOperationsHub::getUrl([], isAbsolute: false))->assertForbidden();
    $this->get(DeliveryOrderResource::getUrl('index', [], isAbsolute: false))->assertForbidden();
    $this->get(DeliveryDriverResource::getUrl('index', [], isAbsolute: false))->assertForbidden();

    $support->givePermissionTo(Permission::findByName('platform_delivery_operations.view', 'web'));
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->actingAs($support->fresh());

    $this->get(DeliveryOperationsHub::getUrl([], isAbsolute: false))->assertSuccessful();
    $this->get(DeliveryOrderResource::getUrl('index', [], isAbsolute: false))->assertSuccessful();
    $this->get(DeliveryDriverResource::getUrl('index', [], isAbsolute: false))->assertSuccessful();
});
