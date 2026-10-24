<?php

declare(strict_types=1);

namespace Modules\Cleaning\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class CleaningBookingRoomPeriod extends Model
{
    protected $fillable = [
        'cleaning_booking_id', 'cleaning_booking_session_id', 'cleaning_booking_room_id',
        'client_key', 'visit_sequence', 'room_type', 'room_size', 'cleaning_mode',
        'scheduled_date', 'scheduled_time', 'required_workers',
        'estimated_work_minutes', 'estimated_duration_minutes',
        'materials', 'special_services', 'notes',
        'base_price', 'addons_total', 'total_price', 'pricing_snapshot',
    ];

    protected function casts(): array
    {
        return [
            'visit_sequence' => 'integer',
            'scheduled_date' => 'date',
            'required_workers' => 'integer',
            'estimated_work_minutes' => 'integer',
            'estimated_duration_minutes' => 'integer',
            'materials' => 'array',
            'special_services' => 'array',
            'base_price' => 'decimal:2',
            'addons_total' => 'decimal:2',
            'total_price' => 'decimal:2',
            'pricing_snapshot' => 'array',
        ];
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(CleaningBooking::class, 'cleaning_booking_id');
    }

    public function visit(): BelongsTo
    {
        return $this->belongsTo(CleaningBookingSession::class, 'cleaning_booking_session_id');
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(CleaningBookingRoom::class, 'cleaning_booking_room_id');
    }
}
