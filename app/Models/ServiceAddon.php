<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\FilterQueries\ServiceAddonFilterQuery;
use Illuminate\Database\Eloquent\Model;
use Modules\Cleaning\Enums\AddonPricingType;

final class ServiceAddon extends Model
{
    use ServiceAddonFilterQuery;

    protected $fillable = [
        'name',
        'slug',
        'pricing_type',
        'price_value',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'pricing_type' => AddonPricingType::class,
            'price_value' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }
}
