<?php

declare(strict_types=1);

namespace Modules\Resturants\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use LogicException;

/** Legacy local QA entry point; the curated seed catalog is now the source of truth. */
final class RestaurantFoodQaClassificationSeeder extends Seeder
{
    public function run(): void
    {
        if (config('app.env') !== 'local'
            || config('database.default') !== 'mysql'
            || ! in_array((string) DB::connection()->getConfig('host'), ['127.0.0.1', 'localhost'], true)
            || DB::connection()->getDatabaseName() !== 'alnadha_worker_test') {
            throw new LogicException('Food QA classification is restricted to the existing local QA database.');
        }

        // Do not overwrite reviewed products or depend on legacy product IDs.
        // New local fixtures are already classified by RestaurantMenuCatalogSeeder.
        $this->command?->info('Legacy QA classifier retired. RestaurantMenuCatalogSeeder now seeds explicit reviewed metadata.');
    }
}
