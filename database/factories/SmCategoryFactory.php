<?php

declare(strict_types=1);

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Supermarket\Models\SmCategory;

final class SmCategoryFactory extends Factory
{
    protected $model = SmCategory::class;

    public function definition(): array
    {
        $name = fake()->words(2, true);

        return [
            'store_id' => SmStoreFactory::new(),
            'name' => $name,
            'slug' => Str::slug($name).'-'.fake()->unique()->word(),
            'description' => fake()->optional()->sentence(),
            'sort_order' => fake()->numberBetween(0, 100),
            'image_path' => fake()->optional()->imageUrl(),
            'is_active' => true,
        ];
    }
}
