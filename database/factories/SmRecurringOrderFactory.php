<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Supermarket\Models\SmRecurringOrder;

final class SmRecurringOrderFactory extends Factory
{
    protected $model = SmRecurringOrder::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'store_id' => SmStoreFactory::new(),
            'status' => fake()->randomElement(['active', 'paused', 'cancelled']),
            'frequency' => fake()->randomElement(['daily', 'weekly', 'monthly']),
            'frequency_config' => ['day' => fake()->numberBetween(1, 7)],
            'next_run_at' => now()->addDays(1),
            'last_run_at' => null,
            'paused_at' => null,
        ];
    }
}
