<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class RestaurantFinancialSetting extends Model
{
    protected $fillable = [
        'base_hour_price',
        'min_hours',
        'addons_pricing',
        'commission_type',
        'commission_value',
        'travel_per_km',
        'travel_minimum',
        'distance_start_point',
        'billing_mode',
        'min_actual_minutes',
        'time_warning_minutes_before_end',
        'coverage_thresholds',
    ];

    public function casts(): array
    {
        return [
            'base_hour_price' => 'decimal:2',
            'commission_value' => 'decimal:2',
            'travel_per_km' => 'decimal:2',
            'travel_minimum' => 'decimal:2',
            'addons_pricing' => 'array',
            'coverage_thresholds' => 'array',
        ];
    }
}
