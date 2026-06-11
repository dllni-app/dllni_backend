<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use Database\Seeders\Permissions\RestaurantOwnerEmployeePermissionsSeeder;
use Illuminate\Database\Seeder;
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
                'password' => bcrypt('password'),
            ]
        );

        $this->call([
            VerifiedUserSeeder::class,
            DashboardPermissionsSeeder::class,
            DeliveryPermissionsSeeder::class,
            DeliveryModuleDataSeeder::class,
            RestaurantOwnerEmployeePermissionsSeeder::class,
            TeamRoleTemplatesSeeder::class,
            AdminUserSeeder::class,
            CleaningWorkerAndSellerSeeder::class,
            WorkerUserSeeder::class,
            CancellationPolicySeeder::class,
            MasterProductSeeder::class,
            RecipeSeeder::class,
            PropertyTypeConfigSeeder::class,
            ServiceAddonSeeder::class,
            TravelCostConfigSeeder::class,
            WorkerSeeder::class,
            CleaningBillingPolicySeeder::class,
            CleaningFinancialSettingsSeeder::class,
            CleaningServiceSeeder::class,
            CleaningBannerSeeder::class,
            RestaurantSeeder::class,
            CleaningBookingSeeder::class,
            CleaningWorkerArabicDataSeeder::class,
            EventBookingSeeder::class,
            SupermarketDatabaseSeeder::class,
            MarketingOfferSeeder::class,
            UserAppScenarioSeeder::class,
        ]);

        if (filter_var((string) env('AI_DEV_DATASET', false), FILTER_VALIDATE_BOOL)) {
            $this->call([
                AiDevelopmentDataSeeder::class,
            ]);
        }
    }
}
