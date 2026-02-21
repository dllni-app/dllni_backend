<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Supermarket\Models\SmInventoryLog;

final class SmInventoryLogFactory extends Factory
{
    protected $model = SmInventoryLog::class;

    public function definition(): array
    {
        $change = fake()->numberBetween(-10, 10);
        $after = fake()->numberBetween(0, 200);

        return [
            'product_id' => SmProductFactory::new(),
            'type' => fake()->randomElement(['adjustment', 'sale', 'restock']),
            'quantity_change' => $change,
            'quantity_after' => $after,
            'reference_type' => null,
            'reference_id' => null,
            'notes' => fake()->optional()->sentence(),
            'user_id' => User::factory(),
        ];
    }
}
