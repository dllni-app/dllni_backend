<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\Resturants\Models\Category;
use Modules\Resturants\Models\Restaurant;

beforeEach(function () {
    Sanctum::actingAs(User::factory()->create());
});

it('lists categories', function () {
    $restaurant = Restaurant::factory()->create();
    Category::factory()->count(3)->create(['restaurant_id' => $restaurant->id]);

    $response = $this->getJson('/api/v1/categories');

    $response->assertOk();
    expect($response->json('data'))->toBeArray()->toHaveCount(3);
});

it('creates a category', function () {
    $restaurant = Restaurant::factory()->create();

    $payload = [
        'restaurantId' => $restaurant->id,
        'name' => 'Desserts',
        'slug' => 'desserts-'.Str::random(4),
        'sortOrder' => 5,
    ];

    $response = $this->postJson('/api/v1/categories', $payload);

    $response->assertCreated();
    $this->assertDatabaseHas('categories', [
        'name' => 'Desserts',
        'restaurant_id' => $restaurant->id,
    ]);
});

it('shows a category', function () {
    $category = Category::factory()->create(['name' => 'Main Course']);

    $response = $this->getJson("/api/v1/categories/{$category->id}");

    $response->assertOk();
    expect($response->json('data.id'))->toBe($category->id);
    expect($response->json('data.name'))->toBe('Main Course');
});

it('updates a category', function () {
    $category = Category::factory()->create(['name' => 'Old Name']);

    $response = $this->putJson("/api/v1/categories/{$category->id}", [
        'restaurantId' => $category->restaurant_id,
        'name' => 'Updated Category',
        'slug' => $category->slug,
        'sortOrder' => 10,
    ]);

    $response->assertOk();
    $this->assertDatabaseHas('categories', [
        'id' => $category->id,
        'name' => 'Updated Category',
    ]);
});

it('deletes a category', function () {
    $category = Category::factory()->create();

    $response = $this->deleteJson("/api/v1/categories/{$category->id}");

    $response->assertNoContent();
    $this->assertDatabaseMissing('categories', ['id' => $category->id]);
});
