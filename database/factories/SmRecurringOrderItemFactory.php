<?php

declare(strict_types=1);

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Supermarket\Models\SmRecurringOrderItem;

final class SmRecurringOrderItemFactory extends Factory
{
    protected $model = SmRecurringOrderItem::class;

    public function definition(): array
    {
        return [
            'recurring_order_id' => SmRecurringOrderFactory::new(),
            'master_product_id' => MasterProductFactory::new(),
            'quantity' => fake()->randomFloat(2, 1, 5),
            'unit' => fake()->optional()->word(),
            'sort_order' => fake()->numberBetween(0, 10),
        ];
    }
}
