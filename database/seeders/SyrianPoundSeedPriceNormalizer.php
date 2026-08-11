<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Support\SyrianPoundPrice;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class SyrianPoundSeedPriceNormalizer extends Seeder
{
    public function run(): void
    {
        // Cleaning catalog/configuration prices.
        $this->normalizeColumns('cleaning_services', ['price']);
        $this->normalizeColumns('service_pricing', ['base_price', 'price_per_sqm']);
        $this->normalizeFixedServiceAddons();
        $this->normalizeColumns('travel_cost_configs', ['cost_per_km', 'fixed_fee']);
        $this->normalizeCleaningFinancialSettings();

        // Supermarket customer-facing product prices.
        $this->normalizeProductTable('sm_products');

        // Restaurant customer-facing menu prices and paid modifiers.
        $this->normalizeProductTable('products');
        $this->normalizeColumns('modifiers', ['price']);
    }

    /** @param array<int, string> $columns */
    private function normalizeColumns(string $table, array $columns): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'id')) {
            return;
        }

        $existingColumns = array_values(array_filter(
            $columns,
            static fn (string $column): bool => Schema::hasColumn($table, $column),
        ));

        if ($existingColumns === []) {
            return;
        }

        DB::table($table)
            ->select(array_merge(['id'], $existingColumns))
            ->orderBy('id')
            ->chunkById(100, function ($rows) use ($table, $existingColumns): void {
                foreach ($rows as $row) {
                    $updates = [];

                    foreach ($existingColumns as $column) {
                        $amount = $row->{$column} ?? null;
                        if ($amount === null) {
                            continue;
                        }

                        $updates[$column] = SyrianPoundPrice::normalize($amount);
                    }

                    if ($updates !== []) {
                        DB::table($table)->where('id', $row->id)->update($updates);
                    }
                }
            }, 'id');
    }

    private function normalizeProductTable(string $table): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'id') || ! Schema::hasColumn($table, 'price')) {
            return;
        }

        $hasDiscountedPrice = Schema::hasColumn($table, 'discounted_price');
        $columns = ['id', 'price'];
        if ($hasDiscountedPrice) {
            $columns[] = 'discounted_price';
        }

        DB::table($table)
            ->select($columns)
            ->orderBy('id')
            ->chunkById(100, function ($rows) use ($table, $hasDiscountedPrice): void {
                foreach ($rows as $row) {
                    $updates = [
                        'price' => SyrianPoundPrice::normalize($row->price),
                    ];

                    if ($hasDiscountedPrice && $row->discounted_price !== null) {
                        $updates['discounted_price'] = SyrianPoundPrice::normalizeDiscount(
                            $row->discounted_price,
                            $row->price,
                        );
                    }

                    DB::table($table)->where('id', $row->id)->update($updates);
                }
            }, 'id');
    }

    private function normalizeFixedServiceAddons(): void
    {
        if (
            ! Schema::hasTable('service_addons')
            || ! Schema::hasColumn('service_addons', 'id')
            || ! Schema::hasColumn('service_addons', 'price_value')
        ) {
            return;
        }

        $query = DB::table('service_addons');
        if (Schema::hasColumn('service_addons', 'pricing_type')) {
            $query->where('pricing_type', 'fixed');
        }

        $query
            ->select(['id', 'price_value'])
            ->orderBy('id')
            ->chunkById(100, function ($rows): void {
                foreach ($rows as $row) {
                    DB::table('service_addons')->where('id', $row->id)->update([
                        'price_value' => SyrianPoundPrice::normalize($row->price_value),
                    ]);
                }
            }, 'id');
    }

    private function normalizeCleaningFinancialSettings(): void
    {
        $table = 'cleaning_financial_settings';
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'id')) {
            return;
        }

        $candidateColumns = [
            'cleaning_base_unit_price',
            'travel_per_km',
            'extension_rate_per_30_minutes',
            'commission_fixed_amount',
            'travel_markup_value',
            'user_cancellation_fee',
            'extension_ranges',
            'commission_type',
            'travel_markup_type',
        ];
        $columns = array_values(array_filter(
            $candidateColumns,
            static fn (string $column): bool => Schema::hasColumn($table, $column),
        ));

        DB::table($table)
            ->select(array_merge(['id'], $columns))
            ->orderBy('id')
            ->chunkById(100, function ($rows): void {
                foreach ($rows as $row) {
                    $updates = [];

                    foreach (['cleaning_base_unit_price', 'travel_per_km', 'extension_rate_per_30_minutes', 'user_cancellation_fee'] as $column) {
                        if (property_exists($row, $column) && $row->{$column} !== null) {
                            $updates[$column] = SyrianPoundPrice::normalize($row->{$column});
                        }
                    }

                    if (
                        property_exists($row, 'commission_fixed_amount')
                        && $row->commission_fixed_amount !== null
                        && (! property_exists($row, 'commission_type') || $row->commission_type === 'fixed')
                    ) {
                        $updates['commission_fixed_amount'] = SyrianPoundPrice::normalize($row->commission_fixed_amount);
                    }

                    if (
                        property_exists($row, 'travel_markup_value')
                        && $row->travel_markup_value !== null
                        && (! property_exists($row, 'travel_markup_type') || $row->travel_markup_type === 'fixed')
                    ) {
                        $updates['travel_markup_value'] = SyrianPoundPrice::normalize($row->travel_markup_value);
                    }

                    if (property_exists($row, 'extension_ranges') && $row->extension_ranges !== null) {
                        $normalizedRanges = $this->normalizeExtensionRanges($row->extension_ranges);
                        if ($normalizedRanges !== null) {
                            $updates['extension_ranges'] = json_encode($normalizedRanges, JSON_THROW_ON_ERROR);
                        }
                    }

                    if ($updates !== []) {
                        DB::table('cleaning_financial_settings')->where('id', $row->id)->update($updates);
                    }
                }
            }, 'id');
    }

    /** @return array<int, array<string, mixed>>|null */
    private function normalizeExtensionRanges(mixed $value): ?array
    {
        $ranges = is_string($value) ? json_decode($value, true) : $value;
        if (! is_array($ranges)) {
            return null;
        }

        foreach ($ranges as &$range) {
            if (is_array($range) && array_key_exists('price', $range)) {
                $range['price'] = SyrianPoundPrice::normalize($range['price']);
            }
        }
        unset($range);

        return $ranges;
    }
}
