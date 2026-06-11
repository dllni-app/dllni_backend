<?php

declare(strict_types=1);

namespace Modules\Cleaning\Database\Seeders;

use App\Models\CleaningFinancialSetting;
use Illuminate\Database\Seeder;
use Modules\Cleaning\Models\CleaningExtendedTimePrice;
use Modules\Cleaning\Services\CleaningExtendedTimePricingService;

final class CleaningFinancialSettingsSeeder extends Seeder
{
    private const EXTENSION_RATE_PER_30_MINUTES = 4500.00;

    /**
     * @var array<int, array{start:int,end:int,price:float}>
     */
    private const EXTENDED_TIME_RANGE_PRICES = [
        ['start' => 0, 'end' => 15, 'price' => 2250.00],
        ['start' => 16, 'end' => 30, 'price' => 4500.00],
        ['start' => 31, 'end' => 45, 'price' => 6750.00],
        ['start' => 46, 'end' => 60, 'price' => 9000.00],
        ['start' => 61, 'end' => 75, 'price' => 11250.00],
        ['start' => 76, 'end' => 90, 'price' => 13500.00],
    ];

    public function run(): void
    {
        $this->seedFinancialSettings();
        $this->seedExtendedTimeRangePrices();
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
                'travel_markup_value' => 0.00,
                'travel_per_km' => 100.00,
                'travel_distance_start_point' => 'worker_home',
                'coverage_thresholds' => [
                    'low' => 3,
                    'ok' => 7,
                ],
                'time_billing_mode' => 'actual',
                'min_billable_minutes' => 30,
                'time_warning_minutes_before_end' => 15,
                'extension_rate_per_30_minutes' => self::EXTENSION_RATE_PER_30_MINUTES,
            ],
        );
    }

    private function seedExtendedTimeRangePrices(): void
    {
        app(CleaningExtendedTimePricingService::class)->ensureFixedRanges();

        foreach (self::EXTENDED_TIME_RANGE_PRICES as $range) {
            CleaningExtendedTimePrice::query()
                ->where('start_minutes', $range['start'])
                ->where('end_minutes', $range['end'])
                ->where('price', '<=', 0)
                ->update([
                    'price' => $range['price'],
                    'updated_at' => now(),
                ]);
        }
    }
}
