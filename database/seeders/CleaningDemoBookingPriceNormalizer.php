<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Support\SyrianPoundPrice;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class CleaningDemoBookingPriceNormalizer extends Seeder
{
    public function run(): void
    {
        $this->normalizeBookingTable('cleaning_bookings');
        $this->normalizeBookingTable('event_bookings');
        $this->normalizeQuotedAmounts();
    }

    private function normalizeBookingTable(string $table): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'id')) {
            return;
        }

        $componentColumns = array_values(array_filter(
            ['base_price', 'addons_total', 'travel_fee', 'cancellation_fee'],
            static fn (string $column): bool => Schema::hasColumn($table, $column),
        ));

        if ($componentColumns === []) {
            return;
        }

        $columns = array_merge(['id'], $componentColumns);
        $hasTotalPrice = Schema::hasColumn($table, 'total_price');
        if ($hasTotalPrice) {
            $columns[] = 'total_price';
        }

        DB::table($table)
            ->select($columns)
            ->orderBy('id')
            ->chunkById(100, function ($rows) use ($table, $componentColumns, $hasTotalPrice): void {
                foreach ($rows as $row) {
                    $updates = [];
                    $normalizedTotal = 0;

                    foreach ($componentColumns as $column) {
                        $value = $row->{$column} ?? null;
                        if ($value === null) {
                            continue;
                        }

                        $normalized = (float) $value <= 0.0
                            ? 0
                            : SyrianPoundPrice::normalize($value);

                        $updates[$column] = $normalized;
                        $normalizedTotal += $normalized;
                    }

                    if ($hasTotalPrice) {
                        $updates['total_price'] = $normalizedTotal;
                    }

                    if ($updates !== []) {
                        DB::table($table)->where('id', $row->id)->update($updates);
                    }
                }
            }, 'id');
    }

    private function normalizeQuotedAmounts(): void
    {
        $table = 'cleaning_time_warnings';

        if (
            ! Schema::hasTable($table)
            || ! Schema::hasColumn($table, 'id')
            || ! Schema::hasColumn($table, 'quoted_amount')
        ) {
            return;
        }

        DB::table($table)
            ->select(['id', 'quoted_amount'])
            ->whereNotNull('quoted_amount')
            ->orderBy('id')
            ->chunkById(100, function ($rows) use ($table): void {
                foreach ($rows as $row) {
                    $value = (float) $row->quoted_amount;

                    DB::table($table)->where('id', $row->id)->update([
                        'quoted_amount' => $value <= 0.0
                            ? 0
                            : SyrianPoundPrice::normalize($value),
                    ]);
                }
            }, 'id');
    }
}
