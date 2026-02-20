<?php

declare(strict_types=1);

use App\Models\User;
use Database\Factories\SmStoreFactory;
use Laravel\Sanctum\Sanctum;
use Modules\Supermarket\Models\SmStore;

beforeEach(function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);
});

it('lists stores', function (): void {
    SmStoreFactory::new()->count(3)->create();

    $response = $this->getJson('/api/v1/sm-stores?perPage=10');

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(3);
});

it('shows a store', function (): void {
    $store = SmStoreFactory::new()->create();

    $response = $this->getJson("/api/v1/sm-stores/{$store->id}");

    $response->assertOk();
    expect($response->json('data.id'))->toBe($store->id);
});

it('creates a store', function (): void {
    $owner = User::factory()->create();

    $payload = [
        'ownerUserId' => $owner->id,
        'name' => 'Central Market',
        'slug' => 'central-market',
    ];

    $response = $this->postJson('/api/v1/sm-stores', $payload);

    $response->assertCreated();
    $this->assertDatabaseHas('sm_stores', [
        'slug' => 'central-market',
        'owner_user_id' => $owner->id,
    ]);
});

it('updates a store', function (): void {
    $store = SmStoreFactory::new()->create([
        'name' => 'Old Name',
        'slug' => 'old-name',
    ]);

    $payload = [
        'name' => 'New Name',
        'slug' => 'new-name',
    ];

    $response = $this->putJson("/api/v1/sm-stores/{$store->id}", $payload);

    $response->assertOk();
    $this->assertDatabaseHas('sm_stores', [
        'id' => $store->id,
        'name' => 'New Name',
        'slug' => 'new-name',
    ]);
});

it('deletes a store', function (): void {
    $store = SmStoreFactory::new()->create();

    $response = $this->deleteJson("/api/v1/sm-stores/{$store->id}");

    $response->assertNoContent();
    $this->assertDatabaseMissing('sm_stores', ['id' => $store->id]);
});
