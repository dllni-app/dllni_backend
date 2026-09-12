<?php

declare(strict_types=1);

namespace Modules\Cleaning\Models;

use App\Models\Worker;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class CleaningEquipmentReservation extends Model
{
    protected $fillable = [
        'cleaning_special_service_equipment_id', 'cleaning_booking_special_service_id',
        'cleaning_booking_session_id', 'worker_id', 'reserved_from', 'reserved_until',
        'status', 'handed_over_at', 'acknowledged_at', 'returned_at', 'failure_reason',
    ];

    public function equipment(): BelongsTo
    {
        return $this->belongsTo(CleaningSpecialServiceEquipment::class, 'cleaning_special_service_equipment_id');
    }

    public function bookingSpecialService(): BelongsTo
    {
        return $this->belongsTo(CleaningBookingSpecialService::class, 'cleaning_booking_special_service_id');
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(CleaningBookingSession::class, 'cleaning_booking_session_id');
    }

    public function worker(): BelongsTo
    {
        return $this->belongsTo(Worker::class);
    }

    public function casts(): array
    {
        return [
            'reserved_from' => 'datetime', 'reserved_until' => 'datetime', 'handed_over_at' => 'datetime',
            'acknowledged_at' => 'datetime', 'returned_at' => 'datetime',
        ];
    }
}
