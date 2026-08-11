<?php

declare(strict_types=1);

namespace Database\Seeders;

use Database\Seeders\Permissions\RestaurantOwnerEmployeePermissionsSeeder;
use Database\Seeders\Permissions\SupermarketOwnerEmployeePermissionsSeeder;
use Illuminate\Database\Seeder;
use Modules\Cleaning\Database\Seeders\AleppoNeighborhoodSeeder;
use Modules\Cleaning\Database\Seeders\CleaningBillingPolicySeeder;
use Modules\Cleaning\Database\Seeders\CleaningFinancialSettingsSeeder;
use Modules\Cleaning\Database\Seeders\CleaningHomeTypeSeeder;
use Modules\Delivery\Database\Seeders\DeliveryPermissionsSeeder;

final class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            DashboardPermissionsSeeder::class,
            DeliveryPermissionsSeeder::class,
            RestaurantOwnerEmployeePermissionsSeeder::class,
            SupermarketOwnerEmployeePermissionsSeeder::class,
            TeamRoleTemplatesSeeder::class,

            AdminUserSeeder::class,
            VerifiedUserSeeder::class,
            RequestedTestUsersSeeder::class,

            CancellationPolicySeeder::class,
            PropertyTypeConfigSeeder::class,
            ServiceAddonSeeder::class,
            TravelCostConfigSeeder::class,
            CleaningBillingPolicySeeder::class,
            CleaningFinancialSettingsSeeder::class,
            CleaningHomeTypeSeeder::class,
            AleppoNeighborhoodSeeder::class,

            SyrianPoundSeedPriceNormalizer::class,
        ]);
    }
}
