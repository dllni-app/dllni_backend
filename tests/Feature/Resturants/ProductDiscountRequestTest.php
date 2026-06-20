<?php

declare(strict_types=1);

use App\Enums\UserModuleType;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Modules\Resturants\Models\Category;
use Modules\Resturants\Models\Restaurant;

function restaurantSellerWithCategory(): array
{
    $owner = User::factory()->create([
        'module_type' => UserModuleType::RestaurantSeller,
    ]);
    $restaurant = Restaurant::factory()->create([
        'user_id' => $owner->id,
        'is_active' => true,
    ]);
    $category = Category::factory()->create([
        'restaurant_id' => $restaurant->id,
    ]);

    Sanctum::actingAs($owner);

    return [$owner, $restaurant, $category];
}

it('rejects client supplied restaurant product final price', function (): void {
    [, , $category] = restaurantSellerWithCategory();

    $this->postJson('/api/v1/restaurant-owner/products', [
        'categoryId' => $category->id,
        'name' => 'QA Product',
        'description' => 'QA product description',
        'price' => 100,
        'discountType' => 'percentage',
        'discountValue' => 10,
        'discountedPrice' => 90,
        'isAvailable' => true,
        'stockQuantity' => 10,
        'lowStockThreshold' => 2,
        'preparationTime' => 20,
        'isFeatured' => false,
    ])->assertUnprocessable()->assertJsonValidationErrors(['discountedPrice']);
});

it('rejects fixed restaurant product discount that is not less than the base price', function (): void {
    [, , $category] = restaurantSellerWithCategory();

    $this->postJson('/api/v1/restaurant-owner/products', [
        'categoryId' => $category->id,
        'name' => 'QA Product',
        'description' => 'QA product description',
        'price' => 100,
        'discountType' => 'fixed',
        'discountValue' => 100,
        'isAvailable' => true,
        'stockQuantity' => 10,
        'lowStockThreshold' => 2,
        'preparationTime' => 20,
        'isFeatured' => false,
    ])->assertUnprocessable()->assertJsonValidationErrors(['discountValue']);
});
