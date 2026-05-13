<?php

declare(strict_types=1);

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Supermarket\Enums\DayOfWeek;
use Modules\Supermarket\Models\SmStoreHours;

final class SmStoreHoursFactory extends Factory
{
    protected $model = SmStoreHours::class;

    public function definition(): array
    {
        return [
            'store_id' => SmStoreFactory::new(),
            'day_of_week' => fake()->randomElement(array_map(
                static fn (DayOfWeek $day): string => $day->value,
                DayOfWeek::cases(),
            )),
            'open_time' => '08:00:00',
            'close_time' => '18:00:00',
            'is_closed' => false,
        ];
    }
}
