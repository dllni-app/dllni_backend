<?php

declare(strict_types=1);

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Supermarket\Models\SmStoreDailyStat;

final class SmStoreDailyStatFactory extends Factory
{
    protected $model = SmStoreDailyStat::class;

    public function definition(): array
    {
        return [
            'store_id' => SmStoreFactory::new(),
            'date' => fake()->unique()->dateTimeBetween('-10 days', 'now')->format('Y-m-d'),
            'orders_count' => fake()->numberBetween(0, 50),
            'orders_revenue' => fake()->randomFloat(2, 0, 500),
            'unique_customers' => fake()->numberBetween(0, 30),
            'new_customers' => fake()->numberBetween(0, 10),
        ];
    }
}
