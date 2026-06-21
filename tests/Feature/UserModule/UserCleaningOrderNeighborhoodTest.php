<?php

declare(strict_types=1);

use App\Models\CancellationPolicy;
use App\Models\User;
use App\Models\Worker;
use Laravel\Sanctum\Sanctum;
use Modules\Cleaning\Enums\CleaningBillingMode;
use Modules\Cleaning\Models\CleaningBillingPolicy;
use Modules\Cleaning\Models\CleaningNeighborhood;

beforeEach(function (): void {
    CancellationPolicy::query()->firstOrCreate(
        ['module' => 'cleaning', 'name' => 'Test Cleaning Cancellation'],
        [
            'description' => 'Test policy',
            'rules' => ['free_until_hours' => 24],
            'is_active' => true,
            'is_default' => true,
        ]
    );

    CleaningBillingPolicy::query()->firstOrCreate(
        ['name' => 'Test Cleaning Billing'],
        [
            'billing_mode' => CleaningBillingMode::FullBookedTime->value,
            'rules' => ['charge_full_booked_hours' => true],
            'is_active' => true,
            'is_default' => true,
        ]
    );
});

it('stores neighborhood id and name on a customer cleaning order', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $neighborhood = CleaningNeighborhood::factory()->create([
        'name_ar' => 'Bustan al-Pasha',
        'name_en' => 'Bustan al-Pasha',
    ]);

    $response = $this->postJson('/api/v1/user/cleaning/orders', [
        'propertyType' => 'apartment',
        'propertyDetails' => [
            'address' => 'Aleppo - Bustan al-Pasha - Granada Street',
            'location_name' => 'Home',
            'rooms' => 2,
            'bedrooms' => 1,
            'bathrooms' => 1,
            'living_room_size' => 'small',
        ],
        'scheduledDate' => now()->addDay()->format('Y-m-d'),
        'scheduledTime' => '09:00',
        'addressLatitude' => 36.22,
        'addressLongitude' => 37.16,
        'neighborhoodId' => $neighborhood->id,
        'termsAccepted' => true,
    ]);

    $response->assertCreated()
        ->assertJsonPath('order.neighborhoodId', $neighborhood->id)
        ->assertJsonPath('order.neighborhoodName', 'Bustan al-Pasha')
        ->assertJsonPath('order.address.neighborhoodId', $neighborhood->id)
        ->assertJsonPath('order.address.neighborhoodName', 'Bustan al-Pasha');

    $this->assertDatabaseHas('cleaning_bookings', [
        'customer_id' => $user->id,
        'neighborhood_id' => $neighborhood->id,
        'neighborhood_name' => 'Bustan al-Pasha',
    ]);
});

it('rejects preferred worker orders when the worker does not cover the selected neighborhood', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $requestedNeighborhood = CleaningNeighborhood::factory()->create(['name_ar' => 'Bustan al-Pasha']);
    $coveredNeighborhood = CleaningNeighborhood::factory()->create(['name_ar' => 'Jamiliyah']);
    $worker = Worker::factory()->create([
        'home_address' => 'Worker Home',
        'home_latitude' => 36.20,
        'home_longitude' => 37.15,
    ]);
    $worker->zones()->create([
        'neighborhood_id' => $coveredNeighborhood->id,
        'name' => $coveredNeighborhood->name_ar,
        'is_active' => true,
    ]);

    $this->postJson('/api/v1/user/cleaning/orders', [
        'propertyType' => 'apartment',
        'propertyDetails' => [
            'address' => 'Aleppo - Bustan al-Pasha',
            'location_name' => 'Home',
            'rooms' => 2,
            'bedrooms' => 1,
            'bathrooms' => 1,
            'living_room_size' => 'small',
        ],
        'scheduledDate' => now()->addDay()->format('Y-m-d'),
        'scheduledTime' => '09:00',
        'addressLatitude' => 36.22,
        'addressLongitude' => 37.16,
        'neighborhoodId' => $requestedNeighborhood->id,
        'preferredWorkerId' => $worker->id,
        'assignmentMode' => 'preferred_worker',
        'termsAccepted' => true,
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['preferredWorkerId']);
});
