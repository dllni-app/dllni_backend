<?php

declare(strict_types=1);

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Resturants\Enums\DiscountType;
use Modules\Resturants\Models\Offer;
use Modules\Resturants\Models\Restaurant;

/**
 * @extends Factory<Offer>
 */
final class OfferFactory extends Factory
{
    protected $model = Offer::class;

    public function definition(): array
    {
        return [
            'restaurant_id' => Restaurant::factory(),
            'name' => fake()->words(3, true),
            'description' => fake()->sentence(),
            'discount_type' => fake()->randomElement([
                DiscountType::Percentage->value,
                DiscountType::FixedAmount->value,
            ]),
            'discount_value' => fake()->randomFloat(2, 5, 50),
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDays(7),
            'is_active' => true,
        ];
    }
}
