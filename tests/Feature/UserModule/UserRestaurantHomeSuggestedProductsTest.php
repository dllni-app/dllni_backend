<?php

declare(strict_types=1);

use Modules\Resturants\Models\CuisineType;
use Modules\Resturants\Models\Product;
use Modules\Resturants\Models\Restaurant;

it('returns suggested products with restaurant, price, rating, and tags', function (): void {
    $cuisine = CuisineType::create([
        'name' => 'Burger',
        'slug' => 'burger',
    ]);

    $restaurant = Restaurant::factory()->create([
        'name' => 'Hamburghini',
        'district' => 'Al-Aziziyah',
        'average_rating' => 4.8,
        'is_active' => true,
    ]);
    $restaurant->cuisineTypes()->attach($cuisine->id);

    $product = Product::factory()->create([
        'restaurant_id' => $restaurant->id,
        'name' => 'Signature Burger',
        'price' => 450,
        'discounted_price' => null,
        'is_available' => true,
        'is_featured' => true,
    ]);

    $response = $this->getJson('/api/v1/user/restaurants/home/suggested-products');

    $response->assertOk();
    $response->assertJsonPath('suggestedProducts.0.productId', $product->id);
    $response->assertJsonPath('suggestedProducts.0.name', 'Signature Burger');
    $response->assertJsonPath('suggestedProducts.0.displayPrice', 450);
    $response->assertJsonPath('suggestedProducts.0.originalPrice', null);
    $response->assertJsonPath('suggestedProducts.0.rating', 4.8);
    $response->assertJsonPath('suggestedProducts.0.restaurant.name', 'Hamburghini');
    $response->assertJsonPath('suggestedProducts.0.restaurant.district', 'Al-Aziziyah');
    expect($response->json('suggestedProducts.0.currency'))->toBeString()->not->toBeEmpty();
    $tagSlugs = collect($response->json('suggestedProducts.0.tags'))->pluck('slug')->all();
    expect($tagSlugs)->toContain('burger');
});

it('exposes original price when a discount applies', function (): void {
    $restaurant = Restaurant::factory()->create([
        'average_rating' => 5,
        'is_active' => true,
    ]);

    Product::factory()->create([
        'restaurant_id' => $restaurant->id,
        'name' => 'Deal meal',
        'price' => 500,
        'discounted_price' => 400,
        'is_available' => true,
    ]);

    $response = $this->getJson('/api/v1/user/restaurants/home/suggested-products');

    $response->assertOk();
    $response->assertJsonPath('suggestedProducts.0.displayPrice', 400);
    $response->assertJsonPath('suggestedProducts.0.originalPrice', 500);
});

it('excludes products from inactive restaurants', function (): void {
    $inactiveRestaurant = Restaurant::factory()->inactive()->create();

    Product::factory()->create([
        'restaurant_id' => $inactiveRestaurant->id,
        'is_available' => true,
    ]);

    $response = $this->getJson('/api/v1/user/restaurants/home/suggested-products');

    $response->assertOk();
    expect($response->json('suggestedProducts'))->toBeArray()->toBeEmpty();
});

it('excludes unavailable products', function (): void {
    $restaurant = Restaurant::factory()->create(['is_active' => true]);

    Product::factory()->create([
        'restaurant_id' => $restaurant->id,
        'is_available' => false,
    ]);

    $response = $this->getJson('/api/v1/user/restaurants/home/suggested-products');

    $response->assertOk();
    expect($response->json('suggestedProducts'))->toBeArray()->toBeEmpty();
});

it('orders higher restaurant ratings before lower ones', function (): void {
    $lowRatedRestaurant = Restaurant::factory()->create([
        'name' => 'Low Stars',
        'average_rating' => 2,
        'is_active' => true,
    ]);
    $highRatedRestaurant = Restaurant::factory()->create([
        'name' => 'High Stars',
        'average_rating' => 5,
        'is_active' => true,
    ]);

    Product::factory()->create([
        'restaurant_id' => $lowRatedRestaurant->id,
        'name' => 'Cheap dish',
        'is_available' => true,
        'is_featured' => false,
    ]);
    $better = Product::factory()->create([
        'restaurant_id' => $highRatedRestaurant->id,
        'name' => 'Top dish',
        'is_available' => true,
        'is_featured' => false,
    ]);

    $response = $this->getJson('/api/v1/user/restaurants/home/suggested-products?limit=10');

    $response->assertOk();
    expect($response->json('suggestedProducts.0.productId'))->toBe($better->id);
});
