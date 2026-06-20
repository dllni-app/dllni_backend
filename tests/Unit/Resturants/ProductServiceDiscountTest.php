<?php

declare(strict_types=1);

use Modules\Resturants\Data\ProductData;
use Modules\Resturants\Models\Category;
use Modules\Resturants\Models\Restaurant;
use Modules\Resturants\Services\ProductService;

it('calculates restaurant product final price from a fixed discount', function (): void {
    $restaurant = Restaurant::factory()->create();
    $category = Category::factory()->create(['restaurant_id' => $restaurant->id]);

    $product = app(ProductService::class)->store(ProductData::from([
        'restaurantId' => $restaurant->id,
        'categoryId' => $category->id,
        'name' => 'QA Fixed Discount Meal',
        'description' => 'Test product',
        'price' => 100,
        'discountedPrice' => null,
        'discountType' => 'fixed',
        'discountValue' => 15,
        'isAvailable' => true,
        'stockQuantity' => 10,
        'lowStockThreshold' => 2,
        'preparationTime' => 20,
        'isFeatured' => false,
    ]));

    $product->refresh();

    expect((float) $product->discounted_price)->toBe(85.0)
        ->and($product->discount_type)->toBe('fixed_amount')
        ->and((float) $product->discount_value)->toBe(15.0);
});

it('calculates restaurant product final price from a percentage discount', function (): void {
    $restaurant = Restaurant::factory()->create();
    $category = Category::factory()->create(['restaurant_id' => $restaurant->id]);

    $product = app(ProductService::class)->store(ProductData::from([
        'restaurantId' => $restaurant->id,
        'categoryId' => $category->id,
        'name' => 'QA Percent Discount Meal',
        'description' => 'Test product',
        'price' => 120,
        'discountedPrice' => null,
        'discountType' => 'percentage',
        'discountValue' => 10,
        'isAvailable' => true,
        'stockQuantity' => 10,
        'lowStockThreshold' => 2,
        'preparationTime' => 20,
        'isFeatured' => false,
    ]));

    $product->refresh();

    expect((float) $product->discounted_price)->toBe(108.0)
        ->and($product->discount_type)->toBe('percentage')
        ->and((float) $product->discount_value)->toBe(10.0);
});
