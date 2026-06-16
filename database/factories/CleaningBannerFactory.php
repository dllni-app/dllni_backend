<?php

declare(strict_types=1);

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Cleaning\Models\CleaningBanner;

/**
 * @extends Factory<CleaningBanner>
 */
final class CleaningBannerFactory extends Factory
{
    protected $model = CleaningBanner::class;

    public function definition(): array
    {
        $title = fake()->sentence(4);

        return [
            'title' => $title,
            'subtitle' => fake()->optional()->sentence(),
            'image_path' => 'cleaning-banners/'.fake()->unique()->uuid().'.jpg',
            'target_url' => fake()->optional()->url(),
            'sort_order' => fake()->numberBetween(0, 100),
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDays(30),
            'is_active' => true,
        ];
    }
}
