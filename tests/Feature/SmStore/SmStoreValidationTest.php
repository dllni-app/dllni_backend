<?php

declare(strict_types=1);

use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Modules\Supermarket\Models\SmStore;

beforeEach(function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);
});

it('rejects duplicate slugs', function (): void {
    SmStore::factory()->create(['slug' => 'unique-slug']);
    $owner = User::factory()->create();

    $payload = [
        'ownerUserId' => $owner->id,
        'name' => 'Another Store',
        'slug' => 'unique-slug',
    ];

    $response = $this->postJson('/api/v1/sm-stores', $payload);

    $response->assertUnprocessable();
    $response->assertJsonValidationErrors(['slug']);
});

it('rejects invalid latitude', function (): void {
    $owner = User::factory()->create();

    $payload = [
        'ownerUserId' => $owner->id,
        'name' => 'Latitude Store',
        'slug' => 'latitude-store',
        'latitude' => 120.5,
    ];

    $response = $this->postJson('/api/v1/sm-stores', $payload);

    $response->assertUnprocessable();
    $response->assertJsonValidationErrors(['latitude']);
});
