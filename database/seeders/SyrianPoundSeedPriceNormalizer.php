<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class SyrianPoundSeedPriceNormalizer extends Seeder
{
    private const int Multiplier = 1000;
    private const int SmallAmountThreshold = 1000;

    public function run(): void
    {
        $this->scaleColumns('cleaning_services', ['price']);
        $this->scaleColumns('service_pricing', ['base_price', 'price_per_sqm']);
        $this->scaleColumns('cleaning_bookings', ['base_price', 'travel_fee', 'total_price']);
    }

    private function scaleColumns(string $table, array $columns): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }
    }
}
