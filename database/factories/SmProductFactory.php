<?php

declare(strict_types=1);

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Supermarket\Models\SmProduct;

final class SmProductFactory extends Factory
{
    protected $model = SmProduct::class;

    public function definition(): array
    {
        return [
            'store_id' => SmStoreFactory::new(),
            'category_id' => SmCategoryFactory::new(),
            'master_product_id' => null,
            'name' => fake()->words(3, true),
            'barcode' => fake()->optional()->ean13(),
            'source_type' => fake()->randomElement(['barcode_scan', 'catalog_search', 'manual', 'template', 'bulk_import']),
            'description' => fake()->optional()->sentence(),
            'price' => fake()->randomFloat(2, 1, 100),
            'discounted_price' => null,
            'stock_quantity' => fake()->numberBetween(0, 500),
            'low_stock_threshold' => fake()->numberBetween(5, 20),
            'expires_at' => fake()->optional()->dateTimeBetween('now', '+1 year'),
            'is_available' => true,
        ];
    }
}
