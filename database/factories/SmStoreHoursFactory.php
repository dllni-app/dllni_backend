<?php

declare(strict_types=1);

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Supermarket\Models\SmStoreHours;

final class SmStoreHoursFactory extends Factory
{
    protected $model = SmStoreHours::class;

    public function definition(): array
    {
        return [
            'store_id' => SmStoreFactory::new(),
            'day_of_week' => fake()->numberBetween(0, 6),
            'opens_at' => '08:00:00',
            'closes_at' => '18:00:00',
            'is_closed' => false,
        ];
    }
}
