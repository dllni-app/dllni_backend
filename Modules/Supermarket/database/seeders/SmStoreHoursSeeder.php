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
            ['opens_at' => '08:00', 'closes_at' => '22:00'],
            ['opens_at' => '08:00', 'closes_at' => '22:00'],
            ['opens_at' => '08:00', 'closes_at' => '22:00'],
            ['opens_at' => '08:00', 'closes_at' => '22:00'],
            ['opens_at' => '08:00', 'closes_at' => '23:00'],
            ['opens_at' => '09:00', 'closes_at' => '23:00'],
            ['opens_at' => '10:00', 'closes_at' => '21:00'],
        ];

        foreach ($stores as $store) {
            for ($dayOfWeek = 0; $dayOfWeek <= 6; $dayOfWeek++) {
                $store->storeHours()->firstOrCreate(
                    ['store_id' => $store->id, 'day_of_week' => $dayOfWeek],
                    [
                        'opens_at' => $schedule[$dayOfWeek]['opens_at'],
                        'closes_at' => $schedule[$dayOfWeek]['closes_at'],
                        'is_closed' => false,
                    ]
                );
            }
        }
    }
}
