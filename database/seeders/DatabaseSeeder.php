<?php

declare(strict_types=1);

namespace Database\Seeders;

use Database\Seeders\Permissions\RestaurantOwnerEmployeePermissionsSeeder;
use Database\Seeders\Permissions\SupermarketOwnerEmployeePermissionsSeeder;
use Illuminate\Database\Seeder;
use Modules\Cleaning\Database\Seeders\AleppoNeighborhoodSeeder;
use Modules\Cleaning\Database\Seeders\CleaningBannerSeeder;
use Modules\Cleaning\Database\Seeders\CleaningBillingPolicySeeder;
use Modules\Cleaning\Database\Seeders\CleaningBookingSeeder;
use Modules\Cleaning\Database\Seeders\CleaningFinancialSettingsSeeder;
use Modules\Cleaning\Database\Seeders\CleaningHomeTypeSeeder;
use Modules\Cleaning\Database\Seeders\CleaningServiceSeeder;
use Modules\Cleaning\Database\Seeders\CleaningWorkerArabicDataSeeder;
use Modules\Cleaning\Database\Seeders\CleaningWorkerExtensionScenarioSeeder;
use Modules\Cleaning\Database\Seeders\EventBookingSeeder;
use Modules\Delivery\Database\Seeders\DeliveryModuleDataSeeder;
use Modules\Delivery\Database\Seeders\DeliveryPermissionsSeeder;
use Modules\Delivery\Database\Seeders\MandoubDeliveryTestUserSeeder;
use Modules\Delivery\Database\Seeders\MandoubPrimaryOfferScenarioSeeder;
use Modules\Resturants\Database\Seeders\RestaurantSeeder;
use Modules\Supermarket\Database\Seeders\SupermarketDatabaseSeeder;

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
        ]);

        if ($this->shouldSeedDemoData()) {
            $this->call($this->demoSeeders());

            if (filter_var((string) env('AI_DEV_DATASET', false), FILTER_VALIDATE_BOOL)) {
                $this->call([
                    AiDevelopmentDataSeeder::class,
                ]);
            }

            $this->call(CleaningDemoBookingPriceNormalizer::class);
        }

        $this->call(SyrianPoundSeedPriceNormalizer::class);
    }

    /**
     * Demo data is opt-in and disabled by default in every environment.
     *
     * @return array<int, class-string>
     */
    private function demoSeeders(): array
    {
        return [
            DeliveryModuleDataSeeder::class,
            MandoubDeliveryTestUserSeeder::class,
            MandoubPrimaryOfferScenarioSeeder::class,
            CleaningWorkersSeeder::class,
            CleaningWorkerAndSellerSeeder::class,
            WorkerUserSeeder::class,
            MasterProductSeeder::class,
            RecipeSeeder::class,
            WorkerSeeder::class,
            WorkerFinancialTypeScenarioSeeder::class,
            CleaningServiceSeeder::class,
            CleaningBannerSeeder::class,
            RestaurantSeeder::class,
            CleaningBookingSeeder::class,
            CleaningWorkerArabicDataSeeder::class,
            CleaningWorkerExtensionScenarioSeeder::class,
            EventBookingSeeder::class,
            SupermarketDatabaseSeeder::class,
            MarketingOfferSeeder::class,
            UserAppScenarioSeeder::class,
            PlatformCouponDemoSeeder::class,
        ];
    }

    private function shouldSeedDemoData(): bool
    {
        return filter_var((string) env('SEED_DEMO_DATA', false), FILTER_VALIDATE_BOOL);
    }
}
