<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var array<int, float> */
    private const PRICE_POINTS = [10.0, 25.0, 50.0, 100.0, 200.0, 500.0];

    public function up(): void
    {
        $this->normalizeColumns('cleaning_services', ['price']);
        $this->normalizeColumns('service_pricing', ['base_price', 'price_per_sqm']);
        $this->normalizeFixedServiceAddons();
        $this->normalizeColumns('travel_cost_configs', ['cost_per_km', 'fixed_fee']);
        $this->normalizeCleaningFinancialSettings();

        $this->normalizeProductTable('sm_products');
        $this->normalizeProductTable('products');
        $this->normalizeColumns('modifiers', ['price']);
    }

    public function down(): void
    {
        // Currency conversion is intentionally irreversible because the original
        // merchant/catalog values cannot be reconstructed after normalization.
    }

    /** @param array<int, string> $columns */
    private function normalizeColumns(string $table, array $columns): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'id')) {
            return;
        }

        $columns = array_values(array_filter(
            $columns,
            static fn (string $column): bool => Schema::hasColumn($table, $column),
        ));

        if ($columns === []) {
            return;
        }

        DB::table($table)
            ->select(array_merge(['id'], $columns))
            ->orderBy('id')
            ->chunkById(100, function ($rows) use ($table, $columns): void {
                foreach ($rows as $row) {
                    $updates = [];
                    foreach ($columns as $column) {
                        if ($row->{$column} !== null) {
                            $updates[$column] = $this->normalizeAmount($row->{$column});
                        }
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

        $hasDiscount = Schema::hasColumn($table, 'discounted_price');
        $columns = ['id', 'price'];
        if ($hasDiscount) {
            $columns[] = 'discounted_price';
        }

        DB::table($table)
            ->select($columns)
            ->orderBy('id')
            ->chunkById(100, function ($rows) use ($table, $hasDiscount): void {
                foreach ($rows as $row) {
                    $updates = ['price' => $this->normalizeAmount($row->price)];

                    if ($hasDiscount && $row->discounted_price !== null) {
                        $updates['discounted_price'] = $this->normalizeDiscount($row->discounted_price, $row->price);
                    }

                    DB::table($table)->where('id', $row->id)->update($updates);
                }
            }, 'id');
    }

    private function normalizeFixedServiceAddons(): void
    {
        if (! Schema::hasTable('service_addons') || ! Schema::hasColumn('service_addons', 'price_value')) {
            return;
        }

        $query = DB::table('service_addons');
        if (Schema::hasColumn('service_addons', 'pricing_type')) {
            $query->where('pricing_type', 'fixed');
        }

        $query->select(['id', 'price_value'])
            ->orderBy('id')
            ->chunkById(100, function ($rows): void {
                foreach ($rows as $row) {
                    DB::table('service_addons')->where('id', $row->id)->update([
                        'price_value' => $this->normalizeAmount($row->price_value),
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
                            $updates[$column] = $this->normalizeAmount($row->{$column});
                        }
                    }

                    if (
                        property_exists($row, 'commission_fixed_amount')
                        && $row->commission_fixed_amount !== null
                        && (! property_exists($row, 'commission_type') || $row->commission_type === 'fixed')
                    ) {
                        $updates['commission_fixed_amount'] = $this->normalizeAmount($row->commission_fixed_amount);
                    }

                    if (
                        property_exists($row, 'travel_markup_value')
                        && $row->travel_markup_value !== null
                        && (! property_exists($row, 'travel_markup_type') || $row->travel_markup_type === 'fixed')
                    ) {
                        $updates['travel_markup_value'] = $this->normalizeAmount($row->travel_markup_value);
                    }

                    if (property_exists($row, 'extension_ranges') && $row->extension_ranges !== null) {
                        $ranges = is_string($row->extension_ranges)
                            ? json_decode($row->extension_ranges, true)
                            : $row->extension_ranges;

                        if (is_array($ranges)) {
                            foreach ($ranges as &$range) {
                                if (is_array($range) && array_key_exists('price', $range)) {
                                    $range['price'] = $this->normalizeAmount($range['price']);
                                }
                            }
                            unset($range);
                            $updates['extension_ranges'] = json_encode($ranges, JSON_THROW_ON_ERROR);
                        }
                    }

                    if ($updates !== []) {
                        DB::table('cleaning_financial_settings')->where('id', $row->id)->update($updates);
                    }
                }
            }, 'id');
    }

    private function normalizeAmount(mixed $amount): float
    {
        $value = $this->convertedValue($amount);
        if ($value <= 0.0) {
            return 0.0;
        }

        foreach (self::PRICE_POINTS as $pricePoint) {
            if ($value <= $pricePoint) {
                return $pricePoint;
            }
        }

        return 500.0;
    }

    private function normalizeDiscount(mixed $discountedAmount, mixed $regularAmount): float
    {
        $discounted = $this->convertedValue($discountedAmount);
        if ($discounted <= 0.0) {
            return 0.0;
        }

        $regular = $this->normalizeAmount($regularAmount);
        $normalizedDiscount = 10.0;

        foreach (self::PRICE_POINTS as $pricePoint) {
            if ($pricePoint > $discounted) {
                break;
            }
            $normalizedDiscount = $pricePoint;
        }

        if ((float) $discountedAmount < (float) $regularAmount && $normalizedDiscount >= $regular) {
            $previous = null;
            foreach (self::PRICE_POINTS as $pricePoint) {
                if ($pricePoint >= $regular) {
                    break;
                }
                $previous = $pricePoint;
            }

            return $previous ?? $regular;
        }

        return min($normalizedDiscount, $regular);
    }

    private function convertedValue(mixed $amount): float
    {
        if (! is_numeric($amount)) {
            return 0.0;
        }

        $value = max(0.0, (float) $amount);

        return $value >= 1000.0 ? $value / 1000.0 : $value;
    }
};
