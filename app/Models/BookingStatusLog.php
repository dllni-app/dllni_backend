<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

final class BookingStatusLog extends Model
{
    protected $fillable = [
        'booking_id',
        'booking_type',
        'from_status',
        'to_status',
        'note',
    ];

    public function booking(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'booking_type', 'booking_id');
    }
}
