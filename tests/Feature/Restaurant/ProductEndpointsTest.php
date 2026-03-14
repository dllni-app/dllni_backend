<?php

declare(strict_types=1);

use App\Enums\UserModuleType;
use Laravel\Sanctum\Sanctum;
use Modules\Resturants\Models\Category;
use Modules\Resturants\Models\Product;
use Modules\Resturants\Models\Restaurant;

it('lists products', function () {
    $restaurant = Restaurant::factory()->create();
    $restaurant->user->update(['module_type' => UserModuleType::RestaurantSeller->value]);
    Sanctum::actingAs($restaurant->user);

    Product::factory()->count(3)->create(['restaurant_id' => $restaurant->id]);

    $response = $this->getJson('/api/v1/products');

    $response->assertOk();
    expect($response->json('data'))->toBeArray()->toHaveCount(3);
});

it('creates a product', function () {
    $restaurant = Restaurant::factory()->create();
    $restaurant->user->update(['module_type' => UserModuleType::RestaurantSeller->value]);
    Sanctum::actingAs($restaurant->user);

    $category = Category::factory()->create(['restaurant_id' => $restaurant->id]);

    $payload = [
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
    $restaurant = Restaurant::factory()->create();
    $restaurant->user->update(['module_type' => UserModuleType::RestaurantSeller->value]);
    Sanctum::actingAs($restaurant->user);

    $product = Product::factory()->create([
        'restaurant_id' => $restaurant->id,
        'name' => 'Special Burger',
    ]);

    $response = $this->getJson("/api/v1/products/{$product->id}");

    $response->assertOk();
    expect($response->json('data.id'))->toBe($product->id);
    expect($response->json('data.name'))->toBe('Special Burger');
});

it('updates a product', function () {
    $restaurant = Restaurant::factory()->create();
    $restaurant->user->update(['module_type' => UserModuleType::RestaurantSeller->value]);
    Sanctum::actingAs($restaurant->user);

    $product = Product::factory()->create([
        'restaurant_id' => $restaurant->id,
        'name' => 'Old Product',
        'price' => 10,
    ]);

    $response = $this->putJson("/api/v1/products/{$product->id}", [
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
    $restaurant = Restaurant::factory()->create();
    $restaurant->user->update(['module_type' => UserModuleType::RestaurantSeller->value]);
    Sanctum::actingAs($restaurant->user);

    $product = Product::factory()->create(['restaurant_id' => $restaurant->id]);

    $response = $this->deleteJson("/api/v1/products/{$product->id}");

    $response->assertNoContent();
    $this->assertDatabaseMissing('products', ['id' => $product->id]);
});
