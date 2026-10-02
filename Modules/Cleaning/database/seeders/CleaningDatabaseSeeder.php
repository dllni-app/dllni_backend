<?php

declare(strict_types=1);

namespace Modules\Cleaning\Database\Seeders;

use Database\Seeders\CleaningDemoBookingPriceNormalizer;
use Database\Seeders\SyrianPoundSeedPriceNormalizer;
use Illuminate\Database\Seeder;

final class CleaningDatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            CleaningBillingPolicySeeder::class,
            CleaningFinancialSettingsSeeder::class,
            CleaningHomeTypeSeeder::class,
            AleppoNeighborhoodSeeder::class,
            CleaningWorkerRealDataSeeder::class,
            CleaningServiceSeeder::class,
            CleaningBannerSeeder::class,
            CleaningCatalogScenarioSeeder::class,
            CleaningBookingSeeder::class,
            EventBookingSeeder::class,
            CleaningOperationalScenarioSeeder::class,
            SyrianPoundSeedPriceNormalizer::class,
            CleaningDemoBookingPriceNormalizer::class,
        ]);
    }
}
