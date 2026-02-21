<?php

declare(strict_types=1);

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Supermarket\Models\SmCartItem;

final class SmCartItemFactory extends Factory
{
    protected $model = SmCartItem::class;

    public function definition(): array
    {
        return [
            'cart_id' => SmCartFactory::new(),
            'product_id' => SmProductFactory::new(),
            'quantity' => fake()->numberBetween(1, 5),
            'unit_price' => fake()->randomFloat(2, 1, 50),
        ];
    }
}
