<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use Database\Seeders\Permissions\RestaurantOwnerEmployeePermissionsSeeder;
use Illuminate\Database\Seeder;
use Modules\Cleaning\Database\Seeders\AleppoNeighborhoodSeeder;
use Modules\Cleaning\Database\Seeders\CleaningBillingPolicySeeder;
use Modules\Cleaning\Database\Seeders\CleaningBannerSeeder;
use Modules\Cleaning\Database\Seeders\CleaningFinancialSettingsSeeder;
use Modules\Cleaning\Database\Seeders\CleaningBookingSeeder;
use Modules\Cleaning\Database\Seeders\CleaningServiceSeeder;
use Modules\Cleaning\Database\Seeders\CleaningWorkerArabicDataSeeder;
use Modules\Cleaning\Database\Seeders\EventBookingSeeder;
use Modules\Delivery\Database\Seeders\DeliveryPermissionsSeeder;
use Modules\Delivery\Database\Seeders\DeliveryModuleDataSeeder;
use Modules\Resturants\Database\Seeders\RestaurantSeeder;
use Modules\Supermarket\Database\Seeders\SupermarketDatabaseSeeder;

final class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        User::updateOrCreate(
            ['email' => 'admin@dllni.sy'],
            [
                'name' => 'مدير النظام',
                'password' => bcrypt(str_rot13('cnffjbeq')),
            ]
        );

        $this->call($this->bootstrapSeeders());

        if ($this->shouldSeedDemoData()) {
            $this->call($this->demoSeeders());

            if (filter_var((string) env('AI_DEV_DATASET', false), FILTER_VALIDATE_BOOL)) {
                $this->call([
                    AiDevelopmentDataSeeder::class,
                ]);
            }
        }
    }

    /**
     * Production should only receive shared config and test user accounts.
     *
     * @return array<int, class-string>
     */
    private function bootstrapSeeders(): array
    {
        return [
            VerifiedUserSeeder::class,
            DashboardPermissionsSeeder::class,
            DeliveryPermissionsSeeder::class,
            DeliveryModuleDataSeeder::class,
            RestaurantOwnerEmployeePermissionsSeeder::class,
            TeamRoleTemplatesSeeder::class,
            AdminUserSeeder::class,
            CleaningWorkersSeeder::class,
            CancellationPolicySeeder::class,
            PropertyTypeConfigSeeder::class,
            ServiceAddonSeeder::class,
            TravelCostConfigSeeder::class,
            CleaningBillingPolicySeeder::class,
            CleaningFinancialSettingsSeeder::class,
            AleppoNeighborhoodSeeder::class,
        ];
    }

    /**
     * Demo data is intentionally excluded from production seeds.
     *
     * @return array<int, class-string>
     */
    private function demoSeeders(): array
    {
        return [
            CleaningWorkerAndSellerSeeder::class,
            WorkerUserSeeder::class,
            MasterProductSeeder::class,
            RecipeSeeder::class,
            WorkerSeeder::class,
            CleaningServiceSeeder::class,
            CleaningBannerSeeder::class,
            RestaurantSeeder::class,
            CleaningBookingSeeder::class,
            CleaningWorkerArabicDataSeeder::class,
            EventBookingSeeder::class,
            SupermarketDatabaseSeeder::class,
            MarketingOfferSeeder::class,
            UserAppScenarioSeeder::class,
        ];
    }

    private function shouldSeedDemoData(): bool
    {
        return config('app.env') !== 'production';
    }
}
