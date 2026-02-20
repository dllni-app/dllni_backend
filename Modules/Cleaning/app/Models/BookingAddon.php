<?php

declare(strict_types=1);

namespace Modules\Cleaning\Models;

use App\Models\ServiceAddon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class BookingAddon extends Model
{
    protected $fillable = [
        'cleaning_booking_id',
        'service_addon_id',
        'quantity',
        'unit_price',
        'total_price',
    ];

    public function cleaningBooking(): BelongsTo
    {
        return $this->belongsTo(CleaningBooking::class);
    }

    public function serviceAddon(): BelongsTo
    {
        return $this->belongsTo(ServiceAddon::class);
    }

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:2',
            'unit_price' => 'decimal:2',
            'total_price' => 'decimal:2',
        ];
    }
}
