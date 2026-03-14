<?php

declare(strict_types=1);

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Resturants\Models\Category;
use Modules\Resturants\Models\Product;
use Modules\Resturants\Models\Restaurant;

/**
 * @extends Factory<Product>
 */
final class ProductFactory extends Factory
{
    protected $model = Product::class;

    public function definition(): array
    {
        $name = fake()->words(3, true);
        $price = fake()->randomFloat(2, 5, 50);

        $restaurant = Restaurant::factory();

        return [
            'restaurant_id' => $restaurant,
            'category_id' => Category::factory()->for($restaurant, 'restaurant'),
            'name' => $name,
            'description' => fake()->sentence(),
            'price' => $price,
            'discounted_price' => null,
            'is_available' => true,
            'stock_quantity' => fake()->numberBetween(10, 100),
            'low_stock_threshold' => 5,
            'preparation_time' => fake()->numberBetween(5, 30),
            'is_featured' => fake()->boolean(20),
        ];
    }

    public function lowStock(): static
    {
        return $this->state(fn () => [
            'stock_quantity' => 2,
            'low_stock_threshold' => 5,
        ]);
    }
}
