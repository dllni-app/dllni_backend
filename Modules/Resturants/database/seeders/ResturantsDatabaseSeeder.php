<?php

declare(strict_types=1);

namespace Modules\Resturants\Database\Seeders;

use Database\Seeders\SyrianPoundSeedPriceNormalizer;
use Illuminate\Database\Seeder;

final class ResturantsDatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            RestaurantSeeder::class,
            SyrianPoundSeedPriceNormalizer::class,
        ]);
    }
}
