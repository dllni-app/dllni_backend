<?php

declare(strict_types=1);

use App\Enums\UserModuleType;
use App\Models\User;
use Database\Factories\SmStoreFactory;
use Database\Seeders\DashboardPermissionsSeeder;
use Database\Seeders\Permissions\SupermarketOwnerEmployeePermissionsSeeder;
use Laravel\Sanctum\Sanctum;
use Modules\Resturants\Models\Restaurant;
use Modules\Resturants\Models\RestaurantStaff;
use Modules\Supermarket\Models\SmStoreStaff;
use Spatie\Permission\Models\Permission;

it('replaces and clears restaurant employee permissions when requested', function (): void {
    $owner = User::factory()->create([
        'module_type' => UserModuleType::RestaurantSeller->value,
    ]);
    $restaurant = Restaurant::factory()->create([
        'user_id' => $owner->id,
    ]);
    Sanctum::actingAs($owner);

    $menuPermission = Permission::query()->firstOrCreate([
        'name' => 'ro.menu',
        'guard_name' => 'web',
    ]);
    $ordersPermission = Permission::query()->firstOrCreate([
        'name' => 'ro.orders',
        'guard_name' => 'web',
    ]);

    $employee = User::factory()->create([
        'module_type' => UserModuleType::RestaurantSeller->value,
    ]);
    RestaurantStaff::query()->create([
        'restaurant_id' => $restaurant->id,
        'user_id' => $employee->id,
        'restaurant_role_id' => null,
        'is_active' => true,
    ]);
    $employee->syncPermissions([$menuPermission->id, $ordersPermission->id]);

    $replaceResponse = $this->patchJson(
        "/api/v1/restaurant-owner/employees/{$employee->id}",
        [
            'syncPermissions' => true,
            'permissionIds' => [$ordersPermission->id],
        ]
    );

    $replaceResponse->assertOk();
    expect($employee->refresh()->getPermissionNames()->sort()->values()->all())
        ->toBe(['ro.orders']);

    $clearResponse = $this->patchJson(
        "/api/v1/restaurant-owner/employees/{$employee->id}",
        ['syncPermissions' => true]
    );

    $clearResponse->assertOk();
    $clearResponse->assertJsonPath('data.permissionIds', []);
    expect($employee->refresh()->getPermissionNames()->all())->toBe([]);
});

it('replaces and clears supermarket employee permissions when requested', function (): void {
    $this->seed(DashboardPermissionsSeeder::class);
    $this->seed(SupermarketOwnerEmployeePermissionsSeeder::class);

    $owner = User::factory()->create([
        'module_type' => UserModuleType::SupermarketSeller->value,
    ]);
    $store = SmStoreFactory::new()->create([
        'owner_user_id' => $owner->id,
    ]);
    Sanctum::actingAs($owner);

    $productsPermission = Permission::query()
        ->where('name', 'so.products')
        ->firstOrFail();
    $ordersPermission = Permission::query()
        ->where('name', 'so.orders')
        ->firstOrFail();

    $employee = User::factory()->create([
        'module_type' => UserModuleType::SupermarketSeller->value,
    ]);
    $staff = SmStoreStaff::query()->create([
        'store_id' => $store->id,
        'user_id' => $employee->id,
        'is_active' => true,
    ]);
    $employee->syncPermissions([$productsPermission->id, $ordersPermission->id]);

    $replaceResponse = $this->patchJson(
        "/api/v1/store-owner/employees/{$staff->id}",
        [
            'syncPermissions' => true,
            'permissionIds' => [$ordersPermission->id],
        ]
    );

    $replaceResponse->assertOk();
    expect($employee->refresh()->getPermissionNames()->sort()->values()->all())
        ->toBe(['so.orders']);

    $clearResponse = $this->patchJson(
        "/api/v1/store-owner/employees/{$staff->id}",
        ['syncPermissions' => true]
    );

    $clearResponse->assertOk();
    $clearResponse->assertJsonPath('data.permissionIds', []);
    expect($employee->refresh()->getPermissionNames()->all())->toBe([]);
});
