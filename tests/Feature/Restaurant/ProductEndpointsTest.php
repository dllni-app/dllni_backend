<?php

declare(strict_types=1);

use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Modules\Resturants\Models\Category;
use Modules\Resturants\Models\Product;
use Modules\Resturants\Models\Restaurant;

beforeEach(function () {
    Sanctum::actingAs(User::factory()->create());
});

it('lists products', function () {
    Product::factory()->count(3)->create();

    $response = $this->getJson('/api/v1/products');

    $response->assertOk();
    expect($response->json('data'))->toBeArray()->toHaveCount(3);
});

it('creates a product', function () {
    $restaurant = Restaurant::factory()->create();
    $category = Category::factory()->create(['restaurant_id' => $restaurant->id]);

    $payload = [
        'restaurantId' => $restaurant->id,
        'categoryId' => $category->id,
        'name' => 'Margherita Pizza',
        'price' => 12.99,
        'isAvailable' => true,
    ];

    $response = $this->postJson('/api/v1/products', $payload);

    $response->assertCreated();
    $this->assertDatabaseHas('products', [
        'name' => 'Margherita Pizza',
        'restaurant_id' => $restaurant->id,
    ]);
});

it('shows a product', function () {
    $product = Product::factory()->create(['name' => 'Special Burger']);

    $response = $this->getJson("/api/v1/products/{$product->id}");

    $response->assertOk();
    expect($response->json('data.id'))->toBe($product->id);
    expect($response->json('data.name'))->toBe('Special Burger');
});

it('updates a product', function () {
    $product = Product::factory()->create(['name' => 'Old Product', 'price' => 10]);

    $response = $this->putJson("/api/v1/products/{$product->id}", [
        'restaurantId' => $product->restaurant_id,
        'categoryId' => $product->category_id,
        'name' => 'Updated Product',
        'price' => 15.99,
        'isAvailable' => true,
    ]);

    $response->assertOk();
    $this->assertDatabaseHas('products', [
        'id' => $product->id,
        'name' => 'Updated Product',
    ]);
});

it('deletes a product', function () {
    $product = Product::factory()->create();

    $response = $this->deleteJson("/api/v1/products/{$product->id}");

    $response->assertNoContent();
    $this->assertDatabaseMissing('products', ['id' => $product->id]);
});
