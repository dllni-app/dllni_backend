<?php

declare(strict_types=1);

use App\Models\User;
use Modules\Resturants\Enums\DiscountType;
use Modules\Resturants\Models\Category;
use Modules\Resturants\Models\Offer;
use Modules\Resturants\Models\Order;
use Modules\Resturants\Models\Product;
use Modules\Resturants\Models\Restaurant;
use Modules\Resturants\Models\Review;

it('returns restaurant details payload', function (): void {
    // Arrange
    $restaurant = Restaurant::factory()->create([
        'name' => 'Burger King',
        'is_active' => true,
    ]);

    $category = Category::factory()->create([
        'restaurant_id' => $restaurant->id,
        'name' => 'Burgers',
        'sort_order' => 1,
    ]);

    $product = Product::factory()->create([
        'restaurant_id' => $restaurant->id,
        'category_id' => $category->id,
        'name' => 'Whopper',
        'price' => 100,
        'discounted_price' => null,
        'is_available' => true,
        'is_featured' => true,
    ]);

    $offer = Offer::factory()->create([
        'restaurant_id' => $restaurant->id,
        'discount_type' => DiscountType::Percentage->value,
        'discount_value' => 20,
        'starts_at' => now()->subHour(),
        'ends_at' => now()->addDay(),
        'is_active' => true,
    ]);
    $offer->products()->attach($product->id);

    $reviewUser = User::factory()->create(['name' => 'Ahmad']);
    $order = Order::factory()->create([
        'user_id' => $reviewUser->id,
        'restaurant_id' => $restaurant->id,
    ]);
    Review::create([
        'user_id' => $reviewUser->id,
        'order_id' => $order->id,
        'restaurant_id' => $restaurant->id,
        'rating' => 5,
        'comment' => 'Great',
    ]);

    $this->assertDatabaseHas('restaurants', ['id' => $restaurant->id]);

    // Act
    $response = $this->getJson("/api/v1/user/restaurants/{$restaurant->id}");

    // Assert
    $response->assertOk()
        ->assertJsonStructure([
            'restaurant',
            'offers',
            'popularProducts',
            'categories',
            'ratingSummary' => ['average', 'total', 'counts'],
            'reviews',
        ])
        ->assertJsonPath('popularProducts.0.price', 100)
        ->assertJsonPath('popularProducts.0.discountedPrice', 80)
        ->assertJsonPath('categories.0.products.0.price', 100)
        ->assertJsonPath('categories.0.products.0.discountedPrice', 80);
});
