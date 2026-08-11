<?php

declare(strict_types=1);

namespace Modules\Cleaning\Database\Seeders;

use App\Models\CleaningFinancialSetting;
use Illuminate\Database\Seeder;
use Modules\Cleaning\Support\CleaningFinancialDefaults;

final class CleaningFinancialSettingsSeeder extends Seeder
{
    /**
     * Kept for backward compatibility with the existing event-assistance API.
     * The event hourly rate is this value × 2, so 200 => 400 SYP/hour/worker.
     * Time-extension prices themselves are read from EXTENSION_RANGES.
     */
    private const EXTENSION_RATE_PER_30_MINUTES = 200;

    private const TRAVEL_PER_KM = 10;

    /**
     * Prices after adopting the new Syrian currency denominations.
     *
     * @var array<int, array{start:int, end:int, price:int}>
     */
    private const EXTENSION_RANGES = [
        ['start' => 0, 'end' => 15, 'price' => 10],
        ['start' => 16, 'end' => 30, 'price' => 25],
        ['start' => 31, 'end' => 45, 'price' => 50],
        ['start' => 46, 'end' => 60, 'price' => 100],
        ['start' => 61, 'end' => 75, 'price' => 200],
        ['start' => 76, 'end' => 90, 'price' => 500],
    ];

    public function run(): void
    {
        $this->seedFinancialSettings();
    }

    private function seedFinancialSettings(): void
    {
        CleaningFinancialSetting::query()->updateOrCreate(
            ['id' => 1],
            [
                'default_commission_rate' => 10.00,
                'commission_type' => 'percent',
                'commission_fixed_amount' => null,
                'vat_rate' => 0.00,
                'travel_markup_type' => 'fixed',
                'travel_markup_value' => 0,
                'travel_per_km' => self::TRAVEL_PER_KM,
                'travel_distance_start_point' => 'worker_'.'home',
                'coverage_thresholds' => [
                    'low' => 3,
                    'ok' => 7,
                ],
                'time_billing_mode' => 'actual',
                'min_billable_minutes' => 30,
                'time_warning_minutes_before_end' => 15,
                'extension_rate_per_30_minutes' => self::EXTENSION_RATE_PER_30_MINUTES,
                'extension_ranges' => self::EXTENSION_RANGES,
                'cleaning_base_unit_price' => CleaningFinancialDefaults::BASE_UNIT_PRICE,
                'cleaning_deep_multiplier' => CleaningFinancialDefaults::DEEP_CLEANING_MULTIPLIER,
                'cleaning_area_margin_multiplier' => CleaningFinancialDefaults::AREA_MARGIN_MULTIPLIER,
                'cleaning_setup_buffer_minutes' => CleaningFinancialDefaults::SETUP_BUFFER_MINUTES,
                'cleaning_room_size_ranges' => CleaningFinancialDefaults::roomSizeRanges(),
                'cleaning_room_pricing_units' => CleaningFinancialDefaults::roomPricingUnits(),
                'cleaning_room_time_minutes' => CleaningFinancialDefaults::roomTimeMinutes(),
            ],
        );
    }
}
