<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class CleaningFinancialSetting extends Model
{
    protected $fillable = [
        'default_commission_rate',
        'vat_rate',
        'travel_markup_type',
        'travel_markup_value',
        'coverage_thresholds',
    ];

    public function casts(): array
    {
        return [
            'default_commission_rate' => 'decimal:2',
            'vat_rate' => 'decimal:2',
            'travel_markup_value' => 'decimal:2',
            'coverage_thresholds' => 'array',
        ];
    }
}
