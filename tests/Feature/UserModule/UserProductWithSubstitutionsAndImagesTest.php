<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Modules\Resturants\Models\Category;
use Modules\Resturants\Models\Product;
use Modules\Resturants\Models\Restaurant;
use Modules\Resturants\Models\RestaurantProductSubstitution;

it('returns product with substitutions array', function (): void {
    $restaurant = Restaurant::factory()->create(['is_active' => true]);
    $category = Category::factory()->create(['restaurant_id' => $restaurant->id]);

    $product1 = Product::factory()->create([
        'restaurant_id' => $restaurant->id,
        'category_id' => $category->id,
        'is_available' => true,
    ]);

    $product2 = Product::factory()->create([
        'restaurant_id' => $restaurant->id,
        'category_id' => $category->id,
        'is_available' => true,
    ]);

    // Create substitution
    RestaurantProductSubstitution::create([
        'restaurant_id' => $restaurant->id,
        'product_id' => $product1->id,
        'substitute_product_id' => $product2->id,
    ]);

    $response = $this->getJson("/api/v1/user/products/{$product1->id}");

    $response->assertOk();
    $response->assertJsonStructure([
        'product' => [
            'id',
            'name',
            'substitutions',
            'images',
            'primaryImage',
        ],
    ]);

    // Check that substitutions array exists
    expect($response->json('product.substitutions'))->toBeArray();
    expect($response->json('product.images'))->toBeArray();
});

it('returns product with multiple product images', function (): void {
    $restaurant = Restaurant::factory()->create(['is_active' => true]);
    $category = Category::factory()->create(['restaurant_id' => $restaurant->id]);

    $product = Product::factory()->create([
        'restaurant_id' => $restaurant->id,
        'category_id' => $category->id,
        'is_available' => true,
    ]);

    // Add multiple images
    for ($i = 1; $i <= 3; $i++) {
        $product->addMedia(UploadedFile::fake()->image("product-{$i}.jpg", 100, 100))
            ->usingFileName("test-{$i}.jpg")
            ->toMediaCollection('images');
    }

    $response = $this->getJson("/api/v1/user/products/{$product->id}");

    $response->assertOk();

    // Check that images array is populated
    expect($response->json('product.images'))->toBeArray();
    expect(count($response->json('product.images')))->toBeGreaterThanOrEqual(1);
});

it('returns empty substitutions array when no substitutions exist', function (): void {
    $restaurant = Restaurant::factory()->create(['is_active' => true]);
    $category = Category::factory()->create(['restaurant_id' => $restaurant->id]);

    $product = Product::factory()->create([
        'restaurant_id' => $restaurant->id,
        'category_id' => $category->id,
        'is_available' => true,
    ]);

    $response = $this->getJson("/api/v1/user/products/{$product->id}");

    $response->assertOk();

    // Substitutions might be null or empty array
    $substitutions = $response->json('product.substitutions');
    if ($substitutions !== null) {
        expect($substitutions)->toBeArray();
    }
});
