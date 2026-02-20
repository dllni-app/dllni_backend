<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\FilterQueries\TravelCostConfigFilterQuery;
use Illuminate\Database\Eloquent\Model;

final class TravelCostConfig extends Model
{
    use TravelCostConfigFilterQuery;

    protected $fillable = [
        'name',
        'max_km',
        'cost_per_km',
        'fixed_fee',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'max_km' => 'decimal:2',
            'cost_per_km' => 'decimal:2',
            'fixed_fee' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }
}
