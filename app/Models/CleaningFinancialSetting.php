<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

final class CleaningFinancialSetting extends Model
{
    use LogsActivity;

    protected $fillable = [
        'default_commission_rate',
        'vat_rate',
        'commission_type',
        'commission_fixed_amount',
        'travel_markup_type',
        'travel_markup_value',
        'travel_per_km',
        'travel_distance_start_point',
        'coverage_thresholds',
        'time_billing_mode',
        'min_billable_minutes',
        'time_warning_minutes_before_end',
        'extension_rate_per_30_minutes',
        'extension_ranges',
        'cleaning_base_unit_price',
        'cleaning_deep_multiplier',
        'user_cancellation_fee',
        'cleaning_area_margin_multiplier',
        'cleaning_setup_buffer_minutes',
        'cleaning_room_size_ranges',
        'cleaning_room_pricing_units',
        'cleaning_room_time_minutes',
    ];

    public function casts(): array
    {
        return [
            'default_commission_rate' => 'decimal:2',
            'vat_rate' => 'decimal:2',
            'commission_fixed_amount' => 'integer',
            'travel_markup_value' => 'decimal:2',
            'travel_per_km' => 'integer',
            'extension_rate_per_30_minutes' => 'integer',
            'cleaning_base_unit_price' => 'integer',
            'cleaning_deep_multiplier' => 'decimal:2',
            'user_cancellation_fee' => 'integer',
            'cleaning_area_margin_multiplier' => 'decimal:2',
            'cleaning_setup_buffer_minutes' => 'integer',
            'coverage_thresholds' => 'array',
            'extension_ranges' => 'array',
            'cleaning_room_size_ranges' => 'array',
            'cleaning_room_pricing_units' => 'array',
            'cleaning_room_time_minutes' => 'array',
        ];
    }

    public static function currentUserCancellationFee(): float
    {
        return max(0.0, (float) (self::query()->value('user_cancellation_fee') ?? 0));
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }
}
