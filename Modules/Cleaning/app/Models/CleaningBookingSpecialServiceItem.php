<?php

declare(strict_types=1);

namespace Modules\Cleaning\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class CleaningBookingSpecialServiceItem extends Model
{
    protected $fillable = [
        'cleaning_booking_special_service_id', 'cleaning_dirtiness_level_id', 'quantity',
        'dirtiness_level', 'price_multiplier', 'base_unit_price', 'total_price', 'notes',
        'before_images', 'after_images',
    ];

    public function bookingSpecialService(): BelongsTo
    {
        return $this->belongsTo(CleaningBookingSpecialService::class, 'cleaning_booking_special_service_id');
    }

    public function dirtinessLevel(): BelongsTo
    {
        return $this->belongsTo(CleaningDirtinessLevel::class, 'cleaning_dirtiness_level_id');
    }

    public function casts(): array
    {
        return [
            'quantity' => 'float', 'price_multiplier' => 'float', 'base_unit_price' => 'float',
            'total_price' => 'float', 'before_images' => 'array', 'after_images' => 'array',
        ];
    }
}
