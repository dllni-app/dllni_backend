<?php

declare(strict_types=1);

namespace Modules\Cleaning\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Cleaning\Traits\FilterQueries\ServicePricingFilterQuery;

final class ServicePricing extends Model
{
    use ServicePricingFilterQuery;

    protected $table = 'service_pricing';

    protected $fillable = [
        'cleaning_service_id',
        'property_type',
        'living_room_size',
        'base_price',
        'price_per_sqm',
        'min_hours',
    ];

    public function cleaningService(): BelongsTo
    {
        return $this->belongsTo(CleaningService::class);
    }

    public function casts(): array
    {
        return [
            'base_price' => 'integer',
            'price_per_sqm' => 'integer',
            'min_hours' => 'decimal:2',
        ];
    }
}
