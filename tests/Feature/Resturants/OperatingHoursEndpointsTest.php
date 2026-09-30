<?php

declare(strict_types=1);

use App\Enums\UserModuleType;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Modules\Resturants\Models\Restaurant;

beforeEach(function () {
    Sanctum::actingAs(User::factory()->create());
});

it('returns operating hours for restaurant', function () {
    $owner = User::factory()->create([
        'module_type' => UserModuleType::RestaurantSeller->value,
    ]);
    $restaurant = Restaurant::factory()->create([
        'user_id' => $owner->id,
    ]);

    Sanctum::actingAs($owner);

    $response = $this->getJson('/api/v1/restaurant-owner/restaurant/operating-hours');

    $response->assertOk();
    $response->assertJsonStructure([
        'data' => [
            'isTemporarilyClosed',
            'dailyHours',
        ],
    ]);
});

it('updates operating hours for restaurant', function () {
    $owner = User::factory()->create([
        'module_type' => UserModuleType::RestaurantSeller->value,
    ]);
    $restaurant = Restaurant::factory()->create([
        'user_id' => $owner->id,
    ]);

    Sanctum::actingAs($owner);

    $response = $this->putJson('/api/v1/restaurant-owner/restaurant/operating-hours', [
        'isTemporarilyClosed' => false,
        'dailyHours' => [
            [
                'dayOfWeek' => 'monday',
                'isEnabled' => true,
                'timeSlots' => [
                    ['startTime' => '09:00 AM', 'endTime' => '11:00 PM'],
                ],
            ],
        ],
    ]);

    $response->assertOk();
    $response->assertJsonPath('data.isTemporarilyClosed', false);
});

it('preserves weekly operating hours when toggling temporary closure only', function () {
    $owner = User::factory()->create([
        'module_type' => UserModuleType::RestaurantSeller->value,
    ]);
    $restaurant = Restaurant::factory()->create([
        'user_id' => $owner->id,
    ]);

    Sanctum::actingAs($owner);

    $this->putJson('/api/v1/restaurant-owner/restaurant/operating-hours', [
        'isTemporarilyClosed' => false,
        'dailyHours' => [
            [
                'dayOfWeek' => 'monday',
                'isEnabled' => true,
                'timeSlots' => [
                    ['startTime' => '09:00 AM', 'endTime' => '11:00 PM'],
                ],
            ],
        ],
    ])->assertOk();

    $this->putJson('/api/v1/restaurant-owner/restaurant/operating-hours', [
        'isTemporarilyClosed' => true,
    ])
        ->assertOk()
        ->assertJsonPath('data.isTemporarilyClosed', true)
        ->assertJsonPath('data.dailyHours.1.isEnabled', true);

    $this->assertDatabaseHas('operating_hours', [
        'restaurant_id' => $restaurant->id,
        'day_of_week' => 'monday',
        'is_closed' => false,
    ]);
});

