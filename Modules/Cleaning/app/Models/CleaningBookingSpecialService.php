<?php

declare(strict_types=1);

namespace Modules\Cleaning\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class CleaningBookingSpecialService extends Model
{
    protected $fillable = [
        'cleaning_booking_id',
        'cleaning_special_service_id',
        'service_name',
        'pricing_unit',
        'dirtiness_level',
        'quantity',
        'base_unit_price',
        'price_multiplier',
        'total_price',
        'equipment_snapshot',
        'notes',
    ];

    public function booking(): BelongsTo
    {
        return $this->belongsTo(CleaningBooking::class, 'cleaning_booking_id');
    }

    public function specialService(): BelongsTo
    {
        return $this->belongsTo(CleaningSpecialService::class, 'cleaning_special_service_id');
    }

    public function casts(): array
    {
        return [
            'quantity' => 'float',
            'base_unit_price' => 'float',
            'price_multiplier' => 'float',
            'total_price' => 'float',
            'equipment_snapshot' => 'array',
        ];
    }
}
