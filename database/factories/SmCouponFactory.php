<?php

declare(strict_types=1);

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Supermarket\Models\SmCoupon;

final class SmCouponFactory extends Factory
{
    protected $model = SmCoupon::class;

    public function definition(): array
    {
        return [
            'store_id' => SmStoreFactory::new(),
            'code' => mb_strtoupper(Str::random(8)),
            'type' => fake()->randomElement(['Fixed', 'Percentage']),
            'value' => fake()->optional()->randomFloat(2, 5, 50),
            'percent' => fake()->optional()->numberBetween(5, 50),
            'min_order_amount' => fake()->optional()->randomFloat(2, 10, 100),
            'max_discount_amount' => fake()->optional()->randomFloat(2, 10, 100),
            'usage_limit' => fake()->optional()->numberBetween(10, 1000),
            'used_count' => 0,
            'starts_at' => now(),
            'ends_at' => now()->addDays(30),
            'is_active' => true,
        ];
    }
}
