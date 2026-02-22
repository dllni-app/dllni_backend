<?php

declare(strict_types=1);

namespace Modules\Supermarket\Database\Seeders;

use Illuminate\Database\Seeder;

final class SupermarketDatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            SmStoreSeeder::class,
            SmStoreHoursSeeder::class,
            SmCategorySeeder::class,
            SmProductSeeder::class,
            SmOfferSeeder::class,
            SmCouponSeeder::class,
            SmCommissionRuleSeeder::class,
            SmOrderSeeder::class,
        ]);
    }
}
