<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class CleaningFinancialSetting extends Model
{
    protected $fillable = [
        'default_commission_rate',
        'vat_rate',
        'commission_type',
        'commission_fixed_amount',
        'travel_markup_type',
        'travel_markup_value',
        'travel_distance_start_point',
        'coverage_thresholds',
        'time_billing_mode',
        'min_billable_minutes',
        'time_warning_minutes_before_end',
    ];

    public function casts(): array
    {
        return [
            'default_commission_rate' => 'decimal:2',
            'vat_rate' => 'decimal:2',
            'commission_fixed_amount' => 'decimal:2',
            'travel_markup_value' => 'decimal:2',
            'coverage_thresholds' => 'array',
        ];
    }
}
