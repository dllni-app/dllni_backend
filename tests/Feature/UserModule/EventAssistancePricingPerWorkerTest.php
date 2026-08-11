<?php

declare(strict_types=1);

use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Modules\Cleaning\Database\Seeders\CleaningFinancialSettingsSeeder;

beforeEach(function (): void {
    $this->seed(CleaningFinancialSettingsSeeder::class);
    Sanctum::actingAs(User::factory()->create());
});

it('prices event assistance per worker per booked hour', function (): void {
    $response = $this->postJson('/api/v1/user/cleaning/orders/estimate-price', [
        'propertyType' => 'event_assistance',
        'propertyDetails' => [
            'eventType' => 'birthday',
            'guestCount' => 20,
            'venueType' => 'apartment',
            'customService' => 'Event assistance',
            'hours' => 2,
        ],
        'assignmentMode' => 'open_count',
        'numberOfWorkers' => 3,
    ]);

    $response->assertOk()
        ->assertJsonPath('pricing.eventHourlyRate', 400)
        ->assertJsonPath('pricing.eventHours', 2)
        ->assertJsonPath('pricing.eventWorkerCount', 3)
        ->assertJsonPath('pricing.basePrice', 2400)
        ->assertJsonPath('pricing.totalPrice', 2400)
        ->assertJsonPath('workerAcceptance.required', 3);
});

it('uses the suggested event team size when worker count is omitted', function (): void {
    $response = $this->postJson('/api/v1/user/cleaning/orders/estimate-price', [
        'propertyType' => 'event_assistance',
        'propertyDetails' => [
            'eventType' => 'large_gathering',
            'guestCount' => 25,
            'venueType' => 'villa',
            'customService' => 'Event assistance',
            'hours' => 1,
        ],
        'assignmentMode' => 'open_count',
    ]);

    $response->assertOk()
        ->assertJsonPath('recommendation.suggestedTeamSize', 3)
        ->assertJsonPath('pricing.eventWorkerCount', 3)
        ->assertJsonPath('pricing.basePrice', 1200)
        ->assertJsonPath('workerAcceptance.required', 3);
});
