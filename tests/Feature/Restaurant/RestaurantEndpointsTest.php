<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\Resturants\Models\Restaurant;

beforeEach(function () {
    Sanctum::actingAs(User::factory()->create());
});

it('lists restaurants', function () {
    Restaurant::factory()->count(3)->create();

    $response = $this->getJson('/api/v1/restaurants');

    $response->assertOk();
    expect($response->json('data'))->toBeArray()->toHaveCount(3);
});

it('creates a restaurant', function () {
    $owner = User::factory()->create(['email' => 'owner@restaurant.com']);

    $payload = [
        'userId' => $owner->id,
        'name' => 'Test Restaurant',
        'slug' => 'test-restaurant-'.Str::random(4),
        'description' => 'A test restaurant',
        'priceRange' => 'medium',
        'isActive' => true,
    ];

    $response = $this->postJson('/api/v1/restaurants', $payload);

    $response->assertCreated();
    $this->assertDatabaseHas('restaurants', [
        'name' => 'Test Restaurant',
        'user_id' => $owner->id,
    ]);
});

it('shows a restaurant', function () {
    $restaurant = Restaurant::factory()->create(['name' => 'Show Me Restaurant']);

    $response = $this->getJson("/api/v1/restaurants/{$restaurant->id}");

    $response->assertOk();
    expect($response->json('data.id'))->toBe($restaurant->id);
    expect($response->json('data.name'))->toBe('Show Me Restaurant');
});

it('updates a restaurant', function () {
    $restaurant = Restaurant::factory()->create(['name' => 'Old Name']);

    $response = $this->putJson("/api/v1/restaurants/{$restaurant->id}", [
        'userId' => $restaurant->user_id,
        'name' => 'Updated Name',
        'slug' => $restaurant->slug,
        'description' => $restaurant->description,
        'isActive' => true,
    ]);

    $response->assertOk();
    $this->assertDatabaseHas('restaurants', [
        'id' => $restaurant->id,
        'name' => 'Updated Name',
    ]);
});

it('deletes a restaurant', function () {
    $restaurant = Restaurant::factory()->create();

    $response = $this->deleteJson("/api/v1/restaurants/{$restaurant->id}");

    $response->assertNoContent();
    $this->assertDatabaseMissing('restaurants', ['id' => $restaurant->id]);
});

it('filters restaurants by isActive', function () {
    Restaurant::factory()->create(['is_active' => true]);
    Restaurant::factory()->inactive()->create();

    $response = $this->getJson('/api/v1/restaurants?filter[isActive]=1');

    $response->assertOk();
    expect($response->json('data'))->toBeArray()->toHaveCount(1);
    expect($response->json('data.0.isActive'))->toBeTrue();
});
