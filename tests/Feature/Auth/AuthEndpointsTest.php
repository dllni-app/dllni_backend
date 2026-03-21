<?php

declare(strict_types=1);

use App\Enums\UserModuleType;
use App\Models\User;
use Database\Seeders\AdminUserSeeder;
use Database\Seeders\DashboardPermissionsSeeder;
use Database\Seeders\Permissions\RestaurantOwnerEmployeePermissionsSeeder;
use Laravel\Sanctum\Sanctum;
use Modules\Resturants\Models\Restaurant;
use Modules\Resturants\Models\RestaurantRole;
use Modules\Resturants\Models\RestaurantStaff;
use Spatie\Permission\Models\Permission;

beforeEach(function (): void {
    $this->seed(DashboardPermissionsSeeder::class);
    $this->seed(AdminUserSeeder::class);
});

it('dashboard: logs in with email and password and returns user, permissions and token', function (): void {
    $response = $this->postJson('/api/dashboard/login', [
        'email' => 'admin@admin.com',
        'password' => 'password',
    ]);

    $response->assertOk()
        ->assertJsonStructure([
            'user' => ['id', 'name', 'email'],
            'permissions' => [],
            'token',
        ]);
    expect($response->json('permissions'))->toBeArray();
    expect($response->json('token'))->toBeString();
});

it('dashboard: returns validation error when login credentials are invalid', function (): void {
    $response = $this->postJson('/api/dashboard/login', [
        'email' => 'admin@admin.com',
        'password' => 'wrong-password',
    ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['email']);
});

it('dashboard: sends reset link for forgot password', function (): void {
    $response = $this->postJson('/api/dashboard/forgot-password', [
        'email' => 'admin@admin.com',
    ]);

    $response->assertOk()
        ->assertJsonPath('message', __('passwords.sent'));
});

it('dashboard: returns validation error when forgot password email does not exist', function (): void {
    $response = $this->postJson('/api/dashboard/forgot-password', [
        'email' => 'missing@example.com',
    ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['email']);
});

it('dashboard: logs out and revokes token', function (): void {
    $admin = User::where('email', 'admin@admin.com')->first();
    Sanctum::actingAs($admin);

    $response = $this->postJson('/api/dashboard/logout');

    $response->assertOk();
});

it('dashboard: returns current user and permissions for me endpoint', function (): void {
    $admin = User::where('email', 'admin@admin.com')->first();
    Sanctum::actingAs($admin);

    $response = $this->getJson('/api/dashboard/me');

    $response->assertOk()
        ->assertJsonStructure([
            'user' => ['id', 'name', 'email'],
            'permissions' => [],
        ]);
    expect($response->json('permissions'))->toBeArray();
});

it('dashboard: rejects me when unauthenticated', function (): void {
    $response = $this->getJson('/api/dashboard/me');

    $response->assertUnauthorized();
});

it('dashboard: rejects logout when unauthenticated', function (): void {
    $response = $this->postJson('/api/dashboard/logout');

    $response->assertUnauthorized();
});

it('user: logs in with phone and password and returns user and token', function (): void {
    $user = User::factory()->create([
        'phone' => '+962791234567',
        'password' => bcrypt('secret'),
    ]);

    $response = $this->postJson('/api/login', [
        'phone' => '+962791234567',
        'password' => 'secret',
    ]);

    $response->assertOk()
        ->assertJsonStructure([
            'user' => ['id', 'name', 'email', 'phone'],
            'token',
        ]);
    expect($response->json('user.phone'))->toBe('+962791234567');
    expect($response->json('token'))->toBeString();
});

it('user: returns validation error when login credentials are invalid', function (): void {
    User::factory()->create(['phone' => '+962791234567']);

    $response = $this->postJson('/api/login', [
        'phone' => '+962791234567',
        'password' => 'wrong-password',
    ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['phone']);
});

it('user: logs out and revokes token', function (): void {
    $user = User::factory()->create(['phone' => '+962791234567']);
    Sanctum::actingAs($user);

    $response = $this->postJson('/api/logout');

    $response->assertOk();
});

it('user: rejects logout when unauthenticated', function (): void {
    $response = $this->postJson('/api/logout');

    $response->assertUnauthorized();
});

it('user: restaurant seller owner login returns role and restaurant_owner permissions', function (): void {
    $this->seed(RestaurantOwnerEmployeePermissionsSeeder::class);

    $owner = User::factory()->create([
        'phone' => '+962791111111',
        'password' => bcrypt('secret'),
        'module_type' => UserModuleType::RestaurantSeller->value,
    ]);

    Restaurant::factory()->create(['user_id' => $owner->id]);

    $response = $this->postJson('/api/login', [
        'phone' => '+962791111111',
        'password' => 'secret',
    ]);

    $response->assertOk()
        ->assertJsonPath('role.slug', 'owner')
        ->assertJsonPath('role.name', 'مالك');

    $permissions = $response->json('permissions');
    expect($permissions)->toBeArray()->not->toBeEmpty();
    expect($permissions[0])->toHaveKeys(['id', 'name', 'slug', 'description', 'group']);
    expect(collect($permissions)->pluck('group')->unique()->values()->all())->toBe(['restaurant_owner']);
});

it('user: restaurant seller staff login returns restaurant role and assigned permissions only', function (): void {
    $this->seed(RestaurantOwnerEmployeePermissionsSeeder::class);

    $owner = User::factory()->create([
        'module_type' => UserModuleType::RestaurantSeller->value,
    ]);
    $restaurant = Restaurant::factory()->create(['user_id' => $owner->id]);

    $restaurantRole = RestaurantRole::query()->create([
        'restaurant_id' => $restaurant->id,
        'name' => 'كاشير',
        'slug' => 'cashier',
    ]);

    $employee = User::factory()->create([
        'phone' => '+962792222222',
        'password' => bcrypt('secret'),
        'module_type' => UserModuleType::RestaurantSeller->value,
    ]);

    RestaurantStaff::query()->create([
        'restaurant_id' => $restaurant->id,
        'user_id' => $employee->id,
        'restaurant_role_id' => $restaurantRole->id,
        'is_active' => true,
    ]);

    $permissionIds = Permission::query()
        ->where('group', 'restaurant_owner')
        ->limit(2)
        ->pluck('id')
        ->all();

    $employee->syncPermissions($permissionIds);

    $response = $this->postJson('/api/login', [
        'phone' => '+962792222222',
        'password' => 'secret',
    ]);

    $response->assertOk()
        ->assertJsonPath('role.id', $restaurantRole->id)
        ->assertJsonPath('role.name', 'كاشير');

    expect($response->json('role'))->not->toHaveKey('slug');

    expect($response->json('permissions'))->toHaveCount(2);
});
