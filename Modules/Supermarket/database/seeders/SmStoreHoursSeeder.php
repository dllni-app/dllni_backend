<?php

declare(strict_types=1);

namespace Modules\Supermarket\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Supermarket\Models\SmStore;

final class SmStoreHoursSeeder extends Seeder
{
    public function run(): void
    {
        $stores = SmStore::all();
        $schedule = [
            ['day_of_week' => 'monday', 'open_time' => '08:00', 'close_time' => '22:00'],
            ['day_of_week' => 'tuesday', 'open_time' => '08:00', 'close_time' => '22:00'],
            ['day_of_week' => 'wednesday', 'open_time' => '08:00', 'close_time' => '22:00'],
            ['day_of_week' => 'thursday', 'open_time' => '08:00', 'close_time' => '22:00'],
            ['day_of_week' => 'friday', 'open_time' => '08:00', 'close_time' => '23:00'],
            ['day_of_week' => 'saturday', 'open_time' => '09:00', 'close_time' => '23:00'],
            ['day_of_week' => 'sunday', 'open_time' => '10:00', 'close_time' => '21:00'],
        ];

        foreach ($stores as $store) {
            foreach ($schedule as $daySchedule) {
                $store->storeHours()->firstOrCreate(
                    ['store_id' => $store->id, 'day_of_week' => $daySchedule['day_of_week']],
                    [
                        'open_time' => $daySchedule['open_time'],
                        'close_time' => $daySchedule['close_time'],
                        'is_closed' => false,
                    ]
                );
            }
        }
    }
}
