<?php

declare(strict_types=1);

use App\Models\User;
use Modules\Resturants\Models\Category;
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

    Product::factory()->create([
        'restaurant_id' => $restaurant->id,
        'category_id' => $category->id,
        'name' => 'Whopper',
        'is_available' => true,
        'is_featured' => true,
    ]);

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
    $response->assertOk()->assertJsonStructure([
        'restaurant',
        'offers',
        'popularProducts',
        'categories',
        'ratingSummary' => ['average', 'total', 'counts'],
        'reviews',
    ]);
});
