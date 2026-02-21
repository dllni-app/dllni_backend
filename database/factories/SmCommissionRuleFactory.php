<?php

declare(strict_types=1);

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Supermarket\Models\SmCommissionRule;

final class SmCommissionRuleFactory extends Factory
{
    protected $model = SmCommissionRule::class;

    public function definition(): array
    {
        return [
            'store_id' => SmStoreFactory::new(),
            'commission_type' => fake()->randomElement(['percentage', 'fixed']),
            'value' => fake()->randomFloat(2, 1, 20),
            'min_order_amount' => fake()->optional()->randomFloat(2, 10, 100),
            'max_commission_amount' => fake()->optional()->randomFloat(2, 10, 100),
            'starts_at' => now(),
            'ends_at' => now()->addMonths(6),
            'is_default' => false,
            'is_active' => true,
        ];
    }

    public function default(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_default' => true,
        ]);
    }
}
