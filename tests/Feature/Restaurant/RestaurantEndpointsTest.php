<?php

declare(strict_types=1);

use App\Enums\UserModuleType;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Modules\Resturants\Models\Restaurant;

beforeEach(function () {
    $this->owner = User::factory()->create([
        'module_type' => UserModuleType::RestaurantSeller->value,
    ]);

    $this->restaurant = Restaurant::factory()->create([
        'user_id' => $this->owner->id,
        'name' => 'Owned Restaurant',
    ]);

    Sanctum::actingAs($this->owner);
});

it('lists only the authenticated restaurant', function () {
    Restaurant::factory()->count(2)->create();

    $response = $this->getJson('/api/v1/restaurants');

    $response->assertOk();
    expect($response->json('data'))->toBeArray()->toHaveCount(1)
        ->and($response->json('data.0.id'))->toBe($this->restaurant->id);
});

it('does not expose generic restaurant creation', function () {
    $this->postJson('/api/v1/restaurants', [
        'name' => 'Unsafe Restaurant',
    ])->assertMethodNotAllowed();
});

it('shows the current restaurant through the owner context endpoint', function () {
    $response = $this->getJson('/api/v1/restaurant-owner/restaurant');

    $response->assertOk();
    expect($response->json('data.id'))->toBe($this->restaurant->id)
        ->and($response->json('data.name'))->toBe('Owned Restaurant');
});

it('updates only the current restaurant through the owner context endpoint', function () {
    $response = $this->putJson('/api/v1/restaurant-owner/restaurant', [
        'name' => 'Updated Name',
        'slug' => $this->restaurant->slug,
        'description' => $this->restaurant->description,
    ]);

    $response->assertOk();
    $this->assertDatabaseHas('restaurants', [
        'id' => $this->restaurant->id,
        'name' => 'Updated Name',
    ]);
});

it('does not expose generic restaurant deletion', function () {
    $this->deleteJson("/api/v1/restaurants/{$this->restaurant->id}")
        ->assertNotFound();

    $this->assertDatabaseHas('restaurants', [
        'id' => $this->restaurant->id,
    ]);
});

it('applies list filters only inside the owned restaurant scope', function () {
    $this->restaurant->update(['is_active' => true]);
    Restaurant::factory()->inactive()->create();

    $response = $this->getJson('/api/v1/restaurants?filter[isActive]=1');

    $response->assertOk();
    expect($response->json('data'))->toBeArray()->toHaveCount(1)
        ->and($response->json('data.0.id'))->toBe($this->restaurant->id)
        ->and($response->json('data.0.isActive'))->toBeTrue();
});
