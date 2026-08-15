<?php

declare(strict_types=1);

use App\Enums\UserModuleType;
use App\Models\User;
use App\Services\SupermarketSellerAuthExtras;
use App\Support\SupermarketOwnerPermissionCatalog;
use Database\Factories\SmStoreFactory;
use Database\Seeders\DashboardPermissionsSeeder;
use Database\Seeders\Permissions\SupermarketOwnerEmployeePermissionsSeeder;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;

beforeEach(function (): void {
    $this->seed(DashboardPermissionsSeeder::class);
    $this->seed(SupermarketOwnerEmployeePermissionsSeeder::class);

    $this->owner = User::factory()->create([
        'module_type' => UserModuleType::SupermarketSeller->value,
    ]);

    Sanctum::actingAs($this->owner);

    SmStoreFactory::new()->create([
        'owner_user_id' => $this->owner->id,
    ]);

    $this->stalePermission = Permission::query()->updateOrCreate(
        [
            'name' => 'products.legacy',
            'guard_name' => config('auth.defaults.guard'),
        ],
        [
            'slug' => 'صلاحية قديمة',
            'description' => 'صلاحية قديمة يجب ألا تظهر لصاحب السوبرماركت',
            'group' => SupermarketOwnerPermissionCatalog::GROUP,
        ]
    );
});

it('returns only the canonical supermarket permission catalog', function (): void {
    $response = $this->getJson('/api/v1/store-owner/permissions');

    $response->assertOk();

    $names = collect($response->json('data.permissions'))->pluck('name')->sort()->values();

    expect($names->all())->toBe(collect(SupermarketOwnerPermissionCatalog::NAMES)->sort()->values()->all());
    expect($names)->not->toContain($this->stalePermission->name);
});

it('keeps stale supermarket permissions out of the auth payload', function (): void {
    $names = collect(SupermarketSellerAuthExtras::permissionsPayload($this->owner))
        ->pluck('name')
        ->sort()
        ->values();

    expect($names->all())->toBe(collect(SupermarketOwnerPermissionCatalog::NAMES)->sort()->values()->all());
    expect($names)->not->toContain($this->stalePermission->name);
});

it('rejects stale supermarket permission ids when creating an employee', function (): void {
    $this->postJson('/api/v1/store-owner/employees', [
        'name' => 'Store Employee',
        'permissionIds' => [$this->stalePermission->id],
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['permissionIds.0']);
});
