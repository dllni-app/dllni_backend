<?php

declare(strict_types=1);

namespace Modules\Cleaning\Support;

use App\Models\CleaningDepositSetting;
use App\Models\CleaningFinancialSetting;
use Illuminate\Database\Eloquent\Model;

final class CleaningRuntimeSettings
{
    /** @return array<string, mixed> */
    public static function financialDefaults(): array
    {
        return [
            'default_commission_rate' => 10.00,
            'vat_rate' => 0.00,
            'commission_type' => 'percent',
            'commission_fixed_amount' => null,
            'travel_markup_type' => 'fixed',
            'travel_markup_value' => 0,
            'travel_per_km' => 10,
            'travel_distance_start_point' => 'worker_home',
            'coverage_thresholds' => ['low' => 3, 'ok' => 7],
            'time_billing_mode' => 'actual',
            'min_billable_minutes' => 30,
            'time_warning_minutes_before_end' => 15,
            'extension_rate_per_30_minutes' => 200,
            'extension_ranges' => self::extensionRanges(),
            'cleaning_base_unit_price' => CleaningFinancialDefaults::BASE_UNIT_PRICE,
            'cleaning_deep_multiplier' => CleaningFinancialDefaults::DEEP_CLEANING_MULTIPLIER,
            'user_cancellation_fee' => 0,
            'cleaning_area_margin_multiplier' => CleaningFinancialDefaults::AREA_MARGIN_MULTIPLIER,
            'cleaning_setup_buffer_minutes' => CleaningFinancialDefaults::SETUP_BUFFER_MINUTES,
            'cleaning_room_size_ranges' => CleaningFinancialDefaults::roomSizeRanges(),
            'cleaning_room_pricing_units' => CleaningFinancialDefaults::roomPricingUnits(),
            'cleaning_room_time_minutes' => CleaningFinancialDefaults::roomTimeMinutes(),
        ];
    }

    /** @return array<string, mixed> */
    public static function depositDefaults(): array
    {
        return [
            'minimum_deposit_amount' => 0,
            'restriction_threshold_percent' => 100,
            'allowance_warning_threshold_percent' => 10,
            'trust_reject_after_accept_penalty' => (int) config('cleaning.trust.reject_after_accept_penalty', 10),
            'trust_minimum_for_dispatch' => 0,
        ];
    }

    /** @return array<int, array{start:int, end:int, price:int}> */
    public static function extensionRanges(): array
    {
        return [
            ['start' => 0, 'end' => 15, 'price' => 10],
            ['start' => 16, 'end' => 30, 'price' => 25],
            ['start' => 31, 'end' => 45, 'price' => 50],
            ['start' => 46, 'end' => 60, 'price' => 100],
            ['start' => 61, 'end' => 75, 'price' => 200],
            ['start' => 76, 'end' => 90, 'price' => 500],
        ];
    }

    public static function financial(): CleaningFinancialSetting
    {
        $settings = CleaningFinancialSetting::query()->first() ?? new CleaningFinancialSetting();
        self::applyDefaults($settings, self::financialDefaults());

        return $settings;
    }

    public static function deposit(): CleaningDepositSetting
    {
        $settings = CleaningDepositSetting::query()->first() ?? new CleaningDepositSetting();
        self::applyDefaults($settings, self::depositDefaults());

        return $settings;
    }

    /**
     * Apply defaults only in memory. Existing database values always win and
     * no row is inserted or updated when configuration data is missing.
     *
     * @param  array<string, mixed>  $defaults
     */
    private static function applyDefaults(Model $model, array $defaults): void
    {
        foreach ($defaults as $key => $value) {
            if ($model->getAttribute($key) === null) {
                $model->setAttribute($key, $value);
            }
        }
    }
}
