<?php

declare(strict_types=1);

namespace Modules\Cleaning\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class ServicePricing extends Model
{
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

    protected function casts(): array
    {
        return [
            'base_price' => 'decimal:2',
            'price_per_sqm' => 'decimal:2',
            'min_hours' => 'decimal:2',
        ];
    }
}
