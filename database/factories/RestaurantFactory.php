<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Resturants\Enums\PriceRange;
use Modules\Resturants\Models\Restaurant;

/**
 * @extends Factory<Restaurant>
 */
final class RestaurantFactory extends Factory
{
    protected $model = Restaurant::class;

    public function definition(): array
    {
        $name = fake()->company().' Restaurant';
        $slug = Str::slug($name).'-'.fake()->unique()->randomNumber(4);

        return [
            'user_id' => User::factory(),
            'name' => $name,
            'slug' => $slug,
            'description' => fake()->sentence(),
            'address' => fake()->address(),
            'latitude' => fake()->latitude(),
            'longitude' => fake()->longitude(),
            'phone' => fake()->phoneNumber(),
            'email' => fake()->companyEmail(),
            'average_rating' => fake()->randomFloat(2, 0, 5),
            'total_reviews' => fake()->numberBetween(0, 500),
            'estimated_preparation_time' => fake()->numberBetween(10, 45),
            'minimum_order_amount' => fake()->randomFloat(2, 0, 50),
            'price_range' => fake()->randomElement(PriceRange::class)->value,
            'reputation_score' => fake()->numberBetween(0, 100),
            'warning_count' => 0,
            'visibility_score' => 100,
            'manual_visibility_override' => false,
            'is_active' => true,
            'is_featured' => fake()->boolean(20),
            'suspension_until' => null,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
