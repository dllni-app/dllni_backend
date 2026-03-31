<?php

declare(strict_types=1);

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\User\Enums\MarketingOfferTheme;
use Modules\User\Models\MarketingOffer;

/**
 * @extends Factory<MarketingOffer>
 */
final class MarketingOfferFactory extends Factory
{
    protected $model = MarketingOffer::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'title' => fake()->sentence(4),
            'description' => fake()->optional()->paragraph(),
            'discount_label' => 'خصم '.fake()->numberBetween(5, 50).'%',
            'promo_code' => mb_strtoupper(fake()->lexify('????')),
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addMonth(),
            'theme' => fake()->randomElement(MarketingOfferTheme::cases()),
            'sort_order' => fake()->numberBetween(0, 100),
            'is_active' => true,
        ];
    }
}
