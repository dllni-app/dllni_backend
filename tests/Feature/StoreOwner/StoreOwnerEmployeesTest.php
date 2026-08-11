<?php

declare(strict_types=1);

use App\Enums\UserModuleType;
use App\Models\User;
use Database\Factories\SmStoreFactory;
use Database\Seeders\DashboardPermissionsSeeder;
use Database\Seeders\Permissions\SupermarketOwnerEmployeePermissionsSeeder;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use Modules\Supermarket\Models\SmStoreStaff;
use Spatie\Permission\Models\Permission;

beforeEach(function (): void {
    $this->seed(DashboardPermissionsSeeder::class);
    $this->seed(SupermarketOwnerEmployeePermissionsSeeder::class);

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
                ['id', 'name', 'slug', 'description', 'group'],
            ],
        ],
    ]);

    $permissions = collect($response->json('data.permissions'));

    expect($permissions)->toHaveCount(6);
    expect($permissions->pluck('name')->sort()->values()->all())->toBe([
        'so.offers_coupons',
        'so.orders',
        'so.products',
        'so.staff_register',
        'so.store_hours',
        'so.warehouse',
    ]);
    expect($permissions->pluck('group')->unique()->values()->all())->toBe(['supermarket_owner']);
});

it('creates employee and syncs selected permissions', function (): void {
    $permissionIds = Permission::query()
        ->whereIn('name', ['so.products', 'so.orders', 'so.warehouse'])
        ->pluck('id')
        ->all();

    $profileImage = UploadedFile::fake()->image('employee.jpg');

    $response = $this->post('/api/v1/store-owner/employees', [
        'name' => 'Store Employee',
        'email' => 'store.employee@example.com',
        'phone' => '+963955000111',
        'permissionIds[]' => $permissionIds,
        'isActive' => true,
        'profileImage' => $profileImage,
    ], ['Accept' => 'application/json']);

    $response->assertCreated();
    $response->assertJsonPath('data.user.email', 'store.employee@example.com');
    $response->assertJsonPath('data.isActive', true);

    expect(collect($response->json('data.permissionIds'))->sort()->values()->all())
        ->toBe(collect($permissionIds)->sort()->values()->all());
    expect($response->json('data.permissions'))->toBeArray();

    $employeeUser = User::query()->where('email', 'store.employee@example.com')->firstOrFail();

    expect($employeeUser->module_type)->toBe(UserModuleType::SupermarketSeller);
    expect($employeeUser->getPermissionNames()->all())->toContain('so.products');
    expect($employeeUser->getPermissionNames()->all())->toContain('so.orders');
    expect($employeeUser->getFirstMediaUrl('primary-image'))->not->toBe('');
    expect($response->json('data.user.profileImageUrl'))->not->toBeNull();

    $this->assertDatabaseHas('sm_store_staff', [
        'store_id' => $this->store->id,
        'user_id' => $employeeUser->id,
        'is_active' => true,
    ]);

    $listResponse = $this->getJson('/api/v1/store-owner/employees');

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
        Permission::query()->where('name', 'so.products')->pluck('id')->all()
    );

    $updatedPermissionIds = Permission::query()
        ->whereIn('name', ['so.orders', 'so.offers_coupons'])
        ->pluck('id')
        ->all();

    $updatedProfileImage = UploadedFile::fake()->image('employee-updated.jpg');

    $response = $this->patch("/api/v1/store-owner/employees/{$staff->id}", [
        'name' => 'Updated Employee',
        'permissionIds[]' => $updatedPermissionIds,
        'profileImage' => $updatedProfileImage,
    ], ['Accept' => 'application/json']);

    $response->assertOk();
    $response->assertJsonPath('data.user.name', 'Updated Employee');

    expect(collect($response->json('data.permissionIds'))->sort()->values()->all())
        ->toBe(collect($updatedPermissionIds)->sort()->values()->all());

    $employee->refresh();

    expect($employee->getPermissionNames()->all())->toContain('so.orders');
    expect($employee->getPermissionNames()->all())->toContain('so.offers_coupons');
    expect($employee->getPermissionNames()->all())->not->toContain('so.products');
    expect($employee->getFirstMediaUrl('primary-image'))->not->toBe('');
    expect($response->json('data.user.profileImageUrl'))->not->toBeNull();

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

it('resolves an active employee store and enforces grouped route permissions', function (): void {
    $employee = User::factory()->create([
        'module_type' => UserModuleType::SupermarketSeller->value,
    ]);

    SmStoreStaff::query()->create([
        'store_id' => $this->store->id,
        'user_id' => $employee->id,
        'is_active' => true,
    ]);

    $employee->syncPermissions(
        Permission::query()->where('name', 'so.orders')->pluck('id')->all()
    );

    Sanctum::actingAs($employee);

    $this->getJson('/api/v1/sm-orders')->assertOk();
    $this->getJson('/api/v1/store-owner/products')->assertForbidden();
});
