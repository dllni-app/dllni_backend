<?php

declare(strict_types=1);

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Supermarket\Models\SmOffer;

final class SmOfferFactory extends Factory
{
    protected $model = SmOffer::class;

    public function definition(): array
    {
        return [
            'store_id' => SmStoreFactory::new(),
            'name' => fake()->words(3, true),
            'description' => fake()->optional()->sentence(),
            'offer_type' => fake()->randomElement(['Discount', 'BuyOneGetOne', 'Bundle']),
            'discount_value' => fake()->optional()->randomFloat(2, 1, 50),
            'discount_percent' => fake()->optional()->numberBetween(5, 50),
            'starts_at' => now(),
            'ends_at' => now()->addDays(30),
            'is_active' => true,
        ];
    }
}
