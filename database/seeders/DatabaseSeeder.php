<?php

declare(strict_types=1);

namespace Database\Seeders;

use Database\Seeders\Permissions\RestaurantOwnerEmployeePermissionsSeeder;
use Database\Seeders\Permissions\SupermarketOwnerEmployeePermissionsSeeder;
use Illuminate\Database\Seeder;
use Modules\Cleaning\Database\Seeders\CleaningBannerSeeder;
use Modules\Cleaning\Database\Seeders\CleaningBookingSeeder;
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

            BaselineConfigurationSeeder::class,
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
     * Demo data is enabled only outside the production environment.
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
        return config('app.env') !== 'production';
    }
}
