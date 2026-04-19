<?php

declare(strict_types=1);

use Modules\Resturants\Models\Category;
use Modules\Resturants\Models\Product;
use Modules\Resturants\Models\Restaurant;

it('returns sectioned menu data for a restaurant', function (): void {
    /** @var \Tests\TestCase $this */
    $restaurant = Restaurant::factory()->create(['is_active' => true]);

    $meals = Category::factory()->create([
        'restaurant_id' => $restaurant->id,
        'name' => 'Meals',
        'sort_order' => 1,
    ]);

    $drinks = Category::factory()->create([
        'restaurant_id' => $restaurant->id,
        'name' => 'Drinks',
        'sort_order' => 2,
    ]);

    Product::factory()->create([
        'restaurant_id' => $restaurant->id,
        'category_id' => $meals->id,
        'name' => 'Margarita Pizza',
        'is_available' => true,
        'price' => 450,
        'discounted_price' => null,
    ]);

    Product::factory()->create([
        'restaurant_id' => $restaurant->id,
        'category_id' => $drinks->id,
        'name' => 'Cola',
        'is_available' => true,
        'price' => 120,
        'discounted_price' => null,
    ]);

    $response = $this->getJson("/api/v1/user/restaurants/{$restaurant->id}/menu-sections");

    $response->assertOk()->assertJsonStructure([
        'restaurantId',
        'itemsPerSection',
        'sections' => [
            '*' => [
                'id',
                'name',
                'sortOrder',
                'totalProducts',
                'items' => [
                    '*' => [
                        'id',
                        'name',
                        'description',
                        'sizeLabel',
                        'displayPrice',
                        'originalPrice',
                        'currency',
                        'primaryImageUrl',
                        'isFeatured',
                        'isFavorite',
                    ],
                ],
            ],
        ],
    ]);

    expect($response->json('sections'))->toHaveCount(2);
    expect($response->json('sections.0.name'))->toBe('Meals');
    expect($response->json('sections.0.items.0.name'))->toBe('Margarita Pizza');
    expect((float) $response->json('sections.0.items.0.displayPrice'))->toBe(450.0);
});

it('filters out unavailable products and empty sections', function (): void {
    /** @var \Tests\TestCase $this */
    $restaurant = Restaurant::factory()->create(['is_active' => true]);

    $meals = Category::factory()->create([
        'restaurant_id' => $restaurant->id,
        'name' => 'Meals',
        'sort_order' => 1,
    ]);

    $emptyCategory = Category::factory()->create([
        'restaurant_id' => $restaurant->id,
        'name' => 'Appetizers',
        'sort_order' => 2,
    ]);

    Product::factory()->create([
        'restaurant_id' => $restaurant->id,
        'category_id' => $meals->id,
        'name' => 'Available Item',
        'is_available' => true,
    ]);

    Product::factory()->create([
        'restaurant_id' => $restaurant->id,
        'category_id' => $meals->id,
        'name' => 'Unavailable Item',
        'is_available' => false,
    ]);

    Product::factory()->create([
        'restaurant_id' => $restaurant->id,
        'category_id' => $emptyCategory->id,
        'name' => 'Unavailable In Empty Category',
        'is_available' => false,
    ]);

    $response = $this->getJson("/api/v1/user/restaurants/{$restaurant->id}/menu-sections");

    $response->assertOk();
    expect($response->json('sections'))->toHaveCount(1);
    expect($response->json('sections.0.items'))->toHaveCount(1);
    expect($response->json('sections.0.items.0.name'))->toBe('Available Item');
});

it('limits returned items per section using itemsPerSection query parameter', function (): void {
    /** @var \Tests\TestCase $this */
    $restaurant = Restaurant::factory()->create(['is_active' => true]);
    $category = Category::factory()->create([
        'restaurant_id' => $restaurant->id,
        'name' => 'Meals',
        'sort_order' => 1,
    ]);

    Product::factory(5)->create([
        'restaurant_id' => $restaurant->id,
        'category_id' => $category->id,
        'is_available' => true,
    ]);

    $response = $this->getJson("/api/v1/user/restaurants/{$restaurant->id}/menu-sections?itemsPerSection=2");

    $response->assertOk();
    expect($response->json('itemsPerSection'))->toBe(2);
    expect($response->json('sections.0.totalProducts'))->toBe(5);
    expect($response->json('sections.0.items'))->toHaveCount(2);
});
