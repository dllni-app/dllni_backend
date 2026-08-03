<?php

declare(strict_types=1);

use App\Enums\UserModuleType;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Modules\Resturants\Models\Restaurant;

beforeEach(function () {
    $this->owner = User::factory()->create([
        'module_type' => UserModuleType::RestaurantSeller->value,
        'phone' => '+963933000001',
    ]);

    $this->restaurant = Restaurant::factory()->create([
        'user_id' => $this->owner->id,
    ]);

    Sanctum::actingAs($this->owner);
});

it('links a newly created employee to the restaurant context', function () {
    $this->postJson('/api/v1/restaurant-owner/employees', [
        'name' => 'Inventory Employee',
        'email' => 'inventory.employee@example.com',
        'phone' => '+963944000222',
        'password' => 'password123',
        'isActive' => true,
    ])->assertCreated();

    $employee = User::query()
        ->where('email', 'inventory.employee@example.com')
        ->firstOrFail();

    $this->assertDatabaseHas('restaurant_staff', [
        'restaurant_id' => $this->restaurant->id,
        'user_id' => $employee->id,
        'is_active' => true,
    ]);

    Sanctum::actingAs($employee);

    $this->getJson('/api/v1/inventory-items')
        ->assertOk();
});

it('does not resolve restaurant context for inactive employees', function () {
    $this->postJson('/api/v1/restaurant-owner/employees', [
        'name' => 'Inactive Employee',
        'email' => 'inactive.employee@example.com',
        'phone' => '+963944000223',
        'password' => 'password123',
        'isActive' => false,
    ])->assertCreated();

    $employee = User::query()
        ->where('email', 'inactive.employee@example.com')
        ->firstOrFail();

    Sanctum::actingAs($employee);

    $this->getJson('/api/v1/inventory-items')
        ->assertForbidden();
});
