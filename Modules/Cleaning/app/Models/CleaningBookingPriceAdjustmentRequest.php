<?php

declare(strict_types=1);

namespace Modules\Cleaning\Models;

use Illuminate\Database\Eloquent\Model;

final class CleaningBookingPriceAdjustmentRequest extends Model
{
    protected $fillable = [
        'cleaning_booking_id',
        'worker_id',
        'old_total_price',
        'proposed_total_price',
        'reason',
        'status',
        'admin_final_total_price',
        'admin_note',
        'reviewed_by',
        'reviewed_at',
    ];
}
