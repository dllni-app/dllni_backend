<?php

declare(strict_types=1);

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Resturants\Models\Category;
use Modules\Resturants\Models\Restaurant;

/**
 * @extends Factory<Category>
 */
final class CategoryFactory extends Factory
{
    protected $model = Category::class;

    public function definition(): array
    {
        $name = fake()->randomElement(['Appetizers', 'Main Course', 'Desserts', 'Drinks', 'Sides', 'Salads']);
        $slug = Str::slug($name).'-'.fake()->unique()->randomNumber(3);

        return [
            'restaurant_id' => Restaurant::factory(),
            'name' => $name,
            'slug' => $slug,
            'sort_order' => fake()->numberBetween(0, 10),
        ];
    }
}
