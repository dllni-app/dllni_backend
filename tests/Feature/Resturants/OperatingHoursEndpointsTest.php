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
