<?php

declare(strict_types=1);

namespace Modules\Cleaning\Database\Seeders;

use App\Models\CleaningFinancialSetting;
use Illuminate\Database\Seeder;

final class CleaningFinancialSettingsSeeder extends Seeder
{
    private const EXTENSION_RATE_PER_30_MINUTES = 4500.00;

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
}
