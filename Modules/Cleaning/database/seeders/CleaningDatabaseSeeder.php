<?php

declare(strict_types=1);

namespace Modules\Cleaning\Database\Seeders;

use Illuminate\Database\Seeder;

final class CleaningDatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            CleaningBillingPolicySeeder::class,
            CleaningServiceSeeder::class,
            CleaningBookingSeeder::class,
            EventBookingSeeder::class,
        ]);
    }
}
