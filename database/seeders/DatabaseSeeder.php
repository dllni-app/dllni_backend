<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Modules\Cleaning\Database\Seeders\CleaningBillingPolicySeeder;
use Modules\Cleaning\Database\Seeders\CleaningBookingSeeder;
use Modules\Cleaning\Database\Seeders\CleaningServiceSeeder;
use Modules\Cleaning\Database\Seeders\EventBookingSeeder;
use Modules\Resturants\Database\Seeders\RestaurantSeeder;
use Modules\Supermarket\Database\Seeders\SupermarketDatabaseSeeder;

final class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        User::create([
            'name' => 'Admin User',
            'email' => 'admin@example.com',
            'password' => bcrypt('password'),
        ]);

        $this->call([
            CancellationPolicySeeder::class,
            MasterProductSeeder::class,
            RecipeSeeder::class,
            PropertyTypeConfigSeeder::class,
            ServiceAddonSeeder::class,
            TravelCostConfigSeeder::class,
            WorkerSeeder::class,
            CleaningBillingPolicySeeder::class,
            CleaningServiceSeeder::class,
            RestaurantSeeder::class,
            CleaningBookingSeeder::class,
            EventBookingSeeder::class,
            SupermarketDatabaseSeeder::class,
        ]);
    }
}
