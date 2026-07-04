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
        $this->scaleColumns('booking_addons', ['unit_price', 'total_price']);
        $this->scaleColumns('cleaning_booking_service', ['unit_price', 'total_price']);
        $this->scaleColumns('cleaning_booking_worker_assignments', [
            'service_share_amount',
            'travel_fee',
            'admin_margin_amount',
            'worker_amount',
        ]);
        $this->scaleColumns('cleaning_bookings', [
            'base_price',
            'addons_total',
            'extension_fee_total',
            'travel_fee',
            'admin_margin_amount',
            'cancellation_fee',
            'total_price',
        ]);
    }

    private function scaleColumns(string $table, array $columns): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'id')) {
            return;
        }

        $existingColumns = [];
        foreach ($columns as $column) {
            if (Schema::hasColumn($table, $column)) {
                $existingColumns[] = $column;
            }
        }

        if ($existingColumns === []) {
            return;
        }

        DB::table($table)
            ->select(array_merge(['id'], $existingColumns))
            ->orderBy('id')
            ->chunk(100, function ($rows) use ($table, $existingColumns): void {
                foreach ($rows as $row) {
                    $updates = [];

                    foreach ($existingColumns as $column) {
                        $amount = (float) ($row->{$column} ?? 0);
                        if ($amount > 0 && $amount < self::SmallAmountThreshold) {
                            $updates[$column] = $amount * self::Multiplier;
                        }
                    }

                    if ($updates !== []) {
                        DB::table($table)->where('id', $row->id)->update($updates);
                    }
                }
            });
    }
}
