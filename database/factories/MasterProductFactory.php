<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\MasterProduct;
use Illuminate\Database\Eloquent\Factories\Factory;

final class MasterProductFactory extends Factory
{
    protected $model = MasterProduct::class;

    public function definition(): array
    {
        return [
            'name' => fake()->words(2, true),
            'barcode' => null,
            'unit' => fake()->randomElement(['piece', 'gram', 'kilogram', 'milliliter', 'liter', 'pack']),
            'brand' => fake()->optional()->company(),
            'description' => fake()->optional()->sentence(),
            'is_active' => true,
        ];
    }
}
