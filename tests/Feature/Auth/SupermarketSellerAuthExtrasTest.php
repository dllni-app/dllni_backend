<?php

declare(strict_types=1);

use App\Enums\UserModuleType;
use App\Models\User;
use Database\Factories\SmStoreFactory;
use Database\Seeders\Permissions\SupermarketOwnerEmployeePermissionsSeeder;
use Modules\Supermarket\Models\SmStoreStaff;
use Spatie\Permission\Models\Permission;

beforeEach(function (): void {
    $this->seed(SupermarketOwnerEmployeePermissionsSeeder::class);
});

it('returns owner role and the full supermarket permission catalog at login', function (): void {
    $owner = User::factory()->create([
        'phone' => '+963955100001',
        'password' => bcrypt('secret'),
        'module_type' => UserModuleType::SupermarketSeller->value,
    ]);

    SmStoreFactory::new()->create([
        'owner_user_id' => $owner->id,
    ]);

    $response = $this->postJson('/api/login', [
        'phone' => '+963955100001',
        'password' => 'secret',
    ]);

    $response->assertOk()
        ->assertJsonPath('role.slug', 'owner')
        ->assertJsonPath('role.name', 'مالك');

    $permissions = collect($response->json('permissions'));

    expect($permissions)->toHaveCount(6);
    expect($permissions->pluck('group')->unique()->values()->all())->toBe(['supermarket_owner']);
    expect($permissions->pluck('name'))->toContain('so.products', 'so.orders', 'so.warehouse');
});

it('returns only assigned supermarket permissions for an employee at login', function (): void {
    $owner = User::factory()->create([
        'module_type' => UserModuleType::SupermarketSeller->value,
    ]);

    $store = SmStoreFactory::new()->create([
        'owner_user_id' => $owner->id,
    ]);

    $employee = User::factory()->create([
        'phone' => '+963955100002',
        'password' => bcrypt('secret'),
        'module_type' => UserModuleType::SupermarketSeller->value,
    ]);

    SmStoreStaff::query()->create([
        'store_id' => $store->id,
        'user_id' => $employee->id,
        'is_active' => true,
    ]);

    $assignedPermissionIds = Permission::query()
        ->whereIn('name', ['so.orders', 'so.warehouse'])
        ->pluck('id')
        ->all();

    $employee->syncPermissions($assignedPermissionIds);

    $response = $this->postJson('/api/login', [
        'phone' => '+963955100002',
        'password' => 'secret',
    ]);

    $response->assertOk()
        ->assertJsonPath('role.slug', 'employee')
        ->assertJsonPath('role.name', 'موظف');

    expect(collect($response->json('permissions'))->pluck('name')->sort()->values()->all())
        ->toBe(['so.orders', 'so.warehouse']);
});
