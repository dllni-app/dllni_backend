<?php

declare(strict_types=1);

use App\Models\User;
use Database\Factories\SmStoreFactory;
use Database\Factories\SmStoreHoursFactory;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);
});

it('lists store hours', function (): void {
    SmStoreHoursFactory::new()->count(3)->create();

    $response = $this->getJson('/api/v1/sm-store-hours?perPage=10');

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(3);
});

it('creates store hours', function (): void {
    $store = SmStoreFactory::new()->create();

    $payload = [
        'storeId' => $store->id,
        'dayOfWeek' => 2,
        'opensAt' => '08:00:00',
        'closesAt' => '17:00:00',
        'isClosed' => false,
    ];

    $response = $this->postJson('/api/v1/sm-store-hours', $payload);

    $response->assertCreated();
    $this->assertDatabaseHas('sm_store_hours', ['store_id' => $store->id, 'day_of_week' => 2]);
});

it('updates store hours', function (): void {
    $hours = SmStoreHoursFactory::new()->create(['is_closed' => false]);

    $payload = [
        'isClosed' => true,
    ];

    $response = $this->putJson("/api/v1/sm-store-hours/{$hours->id}", $payload);

    $response->assertOk();
    $this->assertDatabaseHas('sm_store_hours', ['id' => $hours->id, 'is_closed' => true]);
});

it('deletes store hours', function (): void {
    $hours = SmStoreHoursFactory::new()->create();

    $response = $this->deleteJson("/api/v1/sm-store-hours/{$hours->id}");

    $response->assertNoContent();
    $this->assertDatabaseMissing('sm_store_hours', ['id' => $hours->id]);
});
