<?php

declare(strict_types=1);

use App\Models\User;
use Database\Factories\SmStoreFactory;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    $this->user = User::factory()->create();
    Sanctum::actingAs($this->user);

    // Create a store owned by the authenticated user
    $this->store = SmStoreFactory::new()->create([
        'owner_user_id' => $this->user->id,
        'name' => 'Original Store Name',
        'description' => 'Original description',
    ]);
});

it('retrieves store details', function (): void {
    $response = $this->getJson("/api/v1/store-owner/stores/{$this->store->id}");

    $response->assertOk();
    expect($response->json('data.id'))->toBe($this->store->id);
    expect($response->json('data.name'))->toBe('Original Store Name');
    expect($response->json('data'))->toHaveKey('owner');
});

it('updates store successfully', function (): void {
    $payload = [
        'name' => 'Updated Store Name',
        'description' => 'Updated description',
        'city' => 'Amman',
        'neighborhood' => 'Abdoun',
        'cover' => 'https://cdn.example.com/stores/cover.jpg',
        'logo' => 'https://cdn.example.com/stores/logo.jpg',
        'phone' => '+1234567890',
    ];

    $response = $this->putJson("/api/v1/store-owner/stores/{$this->store->id}", $payload);

    $response->assertOk();
    expect($response->json('data.name'))->toBe('Updated Store Name');
    expect($response->json('data.description'))->toBe('Updated description');
    expect($response->json('data.city'))->toBe('Amman');
    expect($response->json('data.neighborhood'))->toBe('Abdoun');
    expect($response->json('data.cover'))->toBe('https://cdn.example.com/stores/cover.jpg');
    expect($response->json('data.logo'))->toBe('https://cdn.example.com/stores/logo.jpg');

    $this->assertDatabaseHas('sm_stores', [
        'id' => $this->store->id,
        'name' => 'Updated Store Name',
        'description' => 'Updated description',
        'city' => 'Amman',
        'neighborhood' => 'Abdoun',
        'cover' => 'https://cdn.example.com/stores/cover.jpg',
        'logo' => 'https://cdn.example.com/stores/logo.jpg',
    ]);
});

it('validates store name is required when updating', function (): void {
    $payload = [
        'name' => '', // Empty name
        'description' => 'Some description',
    ];

    $response = $this->putJson("/api/v1/store-owner/stores/{$this->store->id}", $payload);

    $response->assertStatus(422);
});

it('returns updated store with loaded relationships', function (): void {
    $payload = [
        'name' => 'New Store Name',
    ];

    $response = $this->putJson("/api/v1/store-owner/stores/{$this->store->id}", $payload);

    $response->assertOk();
    expect($response->json('data'))->toHaveKeys([
        'id',
        'name',
        'slug',
        'city',
        'neighborhood',
        'cover',
        'logo',
        'owner',
        'createdAt',
        'updatedAt',
    ]);
});

it('returns 404 for non-existent store', function (): void {
    $response = $this->getJson('/api/v1/store-owner/stores/99999');

    $response->assertNotFound();
});

it('handles partial updates', function (): void {
    $payload = [
        'description' => 'Only updating description',
    ];

    $response = $this->putJson("/api/v1/store-owner/stores/{$this->store->id}", $payload);

    $response->assertOk();
    expect($response->json('data.description'))->toBe('Only updating description');

    // Verify name hasn't changed
    $this->store->refresh();
    expect($this->store->name)->toBe('Original Store Name');
});
