<?php

declare(strict_types=1);

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Supermarket\Models\SmSmartListItem;

final class SmSmartListItemFactory extends Factory
{
    protected $model = SmSmartListItem::class;

    public function definition(): array
    {
        return [
            'smart_list_id' => SmSmartListFactory::new(),
            'master_product_id' => MasterProductFactory::new(),
            'quantity' => fake()->randomFloat(2, 1, 5),
            'unit' => fake()->optional()->word(),
            'sort_order' => fake()->numberBetween(0, 10),
            'is_included' => true,
        ];
    }
}
