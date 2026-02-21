<?php

declare(strict_types=1);

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Supermarket\Models\SmOrderItem;

final class SmOrderItemFactory extends Factory
{
    protected $model = SmOrderItem::class;

    public function definition(): array
    {
        $quantity = fake()->numberBetween(1, 5);
        $unitPrice = fake()->randomFloat(2, 1, 50);

        return [
            'order_id' => SmOrderFactory::new(),
            'product_id' => SmProductFactory::new(),
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'total_price' => $unitPrice * $quantity,
            'product_name' => fake()->words(3, true),
        ];
    }
}
