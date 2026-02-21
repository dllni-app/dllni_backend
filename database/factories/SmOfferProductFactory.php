<?php

declare(strict_types=1);

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Supermarket\Models\SmOfferProduct;

final class SmOfferProductFactory extends Factory
{
    protected $model = SmOfferProduct::class;

    public function definition(): array
    {
        return [
            'offer_id' => SmOfferFactory::new(),
            'product_id' => SmProductFactory::new(),
            'offer_price' => fake()->optional()->randomFloat(2, 1, 50),
            'max_quantity' => fake()->optional()->numberBetween(1, 10),
        ];
    }
}
