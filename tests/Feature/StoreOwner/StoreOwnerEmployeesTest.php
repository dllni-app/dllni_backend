<?php

declare(strict_types=1);

use App\Enums\UserModuleType;
use App\Models\User;
use Database\Factories\SmStoreFactory;
use Database\Seeders\DashboardPermissionsSeeder;
use Laravel\Sanctum\Sanctum;
use Modules\Supermarket\Models\SmStoreStaff;
use Spatie\Permission\Models\Permission;

beforeEach(function (): void {
    $this->seed(DashboardPermissionsSeeder::class);

    $this->owner = User::factory()->create([
        'module_type' => UserModuleType::SupermarketSeller->value,
    ]);

    Sanctum::actingAs($this->owner);

    $this->store = SmStoreFactory::new()->create([
        'owner_user_id' => $this->owner->id,
    ]);
});

it('returns supermarket owner employee permission catalog', function (): void {
    $response = $this->getJson('/api/v1/store-owner/permissions');

    $response->assertOk();
    $response->assertJsonStructure([
        'data' => [
            'permissions' => [
                ['id', 'name', 'slug', 'group'],
            ],
        ],
    ]);

    $permissionNames = collect($response->json('data.permissions'))->pluck('name');

    expect($permissionNames)->toContain('products.view');
    expect($permissionNames)->toContain('orders.view');
    expect($permissionNames)->toContain('reports.view');
    expect($permissionNames)->not->toContain('system_alerts.view');
});

it('creates employee and syncs selected permissions', function (): void {
    $permissionIds = Permission::query()
        ->whereIn('name', ['products.view', 'orders.view', 'inventory.update'])
        ->pluck('id')
        ->all();

    $response = $this->postJson('/api/v1/store-owner/employees', [
        'storeId' => $this->store->id,
        'name' => 'Store Employee',
        'email' => 'store.employee@example.com',
        'phone' => '+963955000111',
        'permissionIds' => $permissionIds,
        'isActive' => true,
    ]);

    $response->assertCreated();
    $response->assertJsonPath('data.user.email', 'store.employee@example.com');
    $response->assertJsonPath('data.isActive', true);

    $employeeUser = User::query()->where('email', 'store.employee@example.com')->firstOrFail();

    expect($employeeUser->module_type)->toBe(UserModuleType::SupermarketSeller);
    expect($employeeUser->getPermissionNames()->all())->toContain('products.view');
    expect($employeeUser->getPermissionNames()->all())->toContain('orders.view');

    $this->assertDatabaseHas('sm_store_staff', [
        'store_id' => $this->store->id,
        'user_id' => $employeeUser->id,
        'is_active' => true,
    ]);

    $listResponse = $this->getJson("/api/v1/store-owner/employees?storeId={$this->store->id}");

    $listResponse->assertOk();
    expect(collect($listResponse->json('data.employees'))->pluck('user.email')->all())
        ->toContain('store.employee@example.com');
});

it('updates employee profile and permissions', function (): void {
    $employee = User::factory()->create([
        'module_type' => UserModuleType::SupermarketSeller->value,
        'email' => 'employee.update@example.com',
    ]);

    $staff = SmStoreStaff::query()->create([
        'store_id' => $this->store->id,
        'user_id' => $employee->id,
        'is_active' => true,
    ]);

    $employee->syncPermissions(
        Permission::query()->where('name', 'products.view')->pluck('id')->all()
    );

    $updatedPermissionIds = Permission::query()
        ->whereIn('name', ['orders.view', 'offers.view'])
        ->pluck('id')
        ->all();

    $response = $this->patchJson("/api/v1/store-owner/employees/{$staff->id}", [
        'name' => 'Updated Employee',
        'permissionIds' => $updatedPermissionIds,
    ]);

    $response->assertOk();
    $response->assertJsonPath('data.user.name', 'Updated Employee');

    $employee->refresh();

    expect($employee->getPermissionNames()->all())->toContain('orders.view');
    expect($employee->getPermissionNames()->all())->toContain('offers.view');
    expect($employee->getPermissionNames()->all())->not->toContain('products.view');

    $statusResponse = $this->patchJson("/api/v1/store-owner/employees/{$staff->id}/status", [
        'isActive' => false,
    ]);

    $statusResponse->assertOk();
    $statusResponse->assertJsonPath('data.isActive', false);
});

it('forbids managing employees from another owner store', function (): void {
    $anotherOwner = User::factory()->create([
        'module_type' => UserModuleType::SupermarketSeller->value,
    ]);

    $anotherStore = SmStoreFactory::new()->create([
        'owner_user_id' => $anotherOwner->id,
    ]);

    $otherEmployee = User::factory()->create([
        'module_type' => UserModuleType::SupermarketSeller->value,
    ]);

    $otherStaff = SmStoreStaff::query()->create([
        'store_id' => $anotherStore->id,
        'user_id' => $otherEmployee->id,
        'is_active' => true,
    ]);

    $this->patchJson("/api/v1/store-owner/employees/{$otherStaff->id}", [
        'name' => 'Should Fail',
    ])->assertForbidden();
});
