<?php

declare(strict_types=1);

namespace Modules\Cleaning\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Cleaning\Enums\CleaningPriceAdjustmentRequestStatus;

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

    public function casts(): array
    {
        return [
            'status' => CleaningPriceAdjustmentRequestStatus::class,
            'old_total_price' => 'decimal:2',
            'proposed_total_price' => 'decimal:2',
            'admin_final_total_price' => 'decimal:2',
            'reviewed_at' => 'datetime',
        ];
    }
}
