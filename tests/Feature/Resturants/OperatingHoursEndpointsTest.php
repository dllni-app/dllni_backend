<?php

declare(strict_types=1);

use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Modules\Resturants\Models\Restaurant;

beforeEach(function () {
    Sanctum::actingAs(User::factory()->create());
});

it('returns operating hours for restaurant', function () {
    $restaurant = Restaurant::factory()->create();

    $response = $this->getJson('/api/v1/restaurants/'.$restaurant->id.'/operating-hours');

    $response->assertOk();
    $response->assertJsonStructure([
        'data' => [
            'isTemporarilyClosed',
            'dailyHours',
        ],
    ]);
});

it('updates operating hours for restaurant', function () {
    $restaurant = Restaurant::factory()->create();

    $response = $this->putJson('/api/v1/restaurants/'.$restaurant->id.'/operating-hours', [
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
