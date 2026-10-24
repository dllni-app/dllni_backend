<?php

declare(strict_types=1);

use App\Enums\UserModuleType;
use App\Models\User;
use App\Support\SyrianPhoneNumber;
use Laravel\Sanctum\Sanctum;
use Modules\Resturants\Models\Restaurant;
use Spatie\Permission\Models\Permission;

it('normalizes all standard Syrian mobile number spellings', function (): void {
    foreach (['944000923', '0944000923', '963944000923', '+963944000923', '00963944000923'] as $input) {
        expect(SyrianPhoneNumber::normalize($input))->toBe('+963944000923');
    }
    expect(SyrianPhoneNumber::normalize('+491701234567'))->toBe('+491701234567');
});

it('creates staff with canonical phone and signs them in with scoped restaurant details', function (): void {
    $owner = User::factory()->create(['phone' => '+963933800001', 'module_type' => UserModuleType::RestaurantSeller->value]);
    $restaurant = Restaurant::factory()->create(['user_id' => $owner->id, 'name' => 'QA Test Restaurant']);
    $this->seed(\Database\Seeders\Permissions\RestaurantOwnerEmployeePermissionsSeeder::class);
    $permission = Permission::query()->where('name', 'ro.offers_coupons')->firstOrFail();

    Sanctum::actingAs($owner);
    $this->postJson('/api/v1/restaurant-owner/employees', [
        'name' => 'QA isolated employee',
        'phone' => '0944000923',
        'password' => 'StrongTestPassword42!',
        'isActive' => true,
        'permissionIds' => [$permission->id],
    ])->assertCreated();

    $employee = User::query()->where('phone', '+963944000923')->firstOrFail();
    expect($employee->module_type)->toBe(UserModuleType::RestaurantSeller);
    $this->postJson('/api/login', [
        'phone' => '944000923',
        'password' => 'StrongTestPassword42!',
        'module' => 'restaurant_seller',
    ])->assertOk()
        ->assertJsonPath('restaurant.name', 'QA Test Restaurant')
        ->assertJsonPath('restaurant.id', $restaurant->id)
        ->assertJsonStructure(['permissions']);
    expect($employee->getPermissionNames()->all())->toContain('ro.offers_coupons');
});
it('accepts a legacy locally stored staff phone without granting extra roles', function (): void {
    $owner = User::factory()->create(['phone' => '+963933800002', 'module_type' => UserModuleType::RestaurantSeller->value]);
    $restaurant = Restaurant::factory()->create(['user_id' => $owner->id]);
    $employee = User::factory()->create([
        'phone' => '0944000924',
        'password' => 'OldEmployeePassword!',
        'module_type' => UserModuleType::RestaurantSeller->value,
    ]);
    \Modules\Resturants\Models\RestaurantStaff::query()->create([
        'restaurant_id' => $restaurant->id,
        'user_id' => $employee->id,
        'is_active' => true,
    ]);
    $this->postJson('/api/login', [
        'phone' => '+963944000924',
        'password' => 'OldEmployeePassword!',
        'module' => 'restaurant_seller',
    ])->assertOk()->assertJsonPath('restaurant.id', $restaurant->id)->assertJsonCount(0, 'permissions');
});

it('rejects sign-in for a disabled restaurant employee even with correct password', function (): void {
    $owner = User::factory()->create(['phone' => '+963933800003', 'module_type' => UserModuleType::RestaurantSeller->value]);
    $restaurant = Restaurant::factory()->create(['user_id' => $owner->id]);
    $employee = User::factory()->create([
        'phone' => '+963944000925',
        'password' => 'DisabledEmployeePassword!',
        'module_type' => UserModuleType::RestaurantSeller->value,
    ]);
    \Modules\Resturants\Models\RestaurantStaff::query()->create([
        'restaurant_id' => $restaurant->id,
        'user_id' => $employee->id,
        'is_active' => false,
    ]);
    $this->postJson('/api/login', [
        'phone' => '+963944000925',
        'password' => 'DisabledEmployeePassword!',
        'module' => 'restaurant_seller',
    ])->assertUnprocessable();
});
