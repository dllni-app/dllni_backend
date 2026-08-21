<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Cleaning\Database\Seeders\AleppoNeighborhoodSeeder;
use Modules\Cleaning\Database\Seeders\CleaningBillingPolicySeeder;
use Modules\Cleaning\Database\Seeders\CleaningDepositSettingsSeeder;
use Modules\Cleaning\Database\Seeders\CleaningFinancialSettingsSeeder;
use Modules\Cleaning\Database\Seeders\CleaningHomeTypeSeeder;
use Modules\Cleaning\Database\Seeders\CleaningServiceSeeder;

final class BaselineConfigurationSeeder extends Seeder
{
    public function run(): void
    {
        // These seeders are insert-only for their natural keys, so they can safely
        // fill missing baseline rows without replacing administrator changes.
        $this->call([
            CancellationPolicySeeder::class,
            PropertyTypeConfigSeeder::class,
            CleaningBillingPolicySeeder::class,
            CleaningDepositSettingsSeeder::class,
        ]);

        // These legacy seeders intentionally update known rows when called directly.
        // During normal application seeding, only use them to initialize a completely
        // empty configuration table so production/admin-managed values are preserved.
        $this->callWhenTableEmpty('service_addons', ServiceAddonSeeder::class);
        $this->callWhenTableEmpty('travel_cost_configs', TravelCostConfigSeeder::class);
        $this->callWhenTableEmpty('cleaning_financial_settings', CleaningFinancialSettingsSeeder::class);
        $this->callWhenTableEmpty('cleaning_home_types', CleaningHomeTypeSeeder::class);
        $this->callWhenTableEmpty('cleaning_neighborhoods', AleppoNeighborhoodSeeder::class);
        $this->callWhenTableEmpty('cleaning_services', CleaningServiceSeeder::class);
    }

    /** @param class-string<Seeder> $seeder */
    private function callWhenTableEmpty(string $table, string $seeder): void
    {
        if (! Schema::hasTable($table) || DB::table($table)->exists()) {
            return;
        }

        $this->call($seeder);
    }
}
