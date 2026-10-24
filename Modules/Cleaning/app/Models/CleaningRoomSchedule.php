<?php

declare(strict_types=1);

namespace Modules\Cleaning\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class CleaningRoomSchedule extends Model
{
    protected $guarded = ['id'];

    public function room(): BelongsTo
    {
        return $this->belongsTo(CleaningBookingRoom::class, 'cleaning_booking_room_id');
    }

    public function visit(): BelongsTo
    {
        return $this->belongsTo(CleaningBookingSession::class, 'cleaning_booking_session_id');
    }

    public function casts(): array
    {
        return ['starts_at' => 'immutable_datetime', 'ends_at' => 'immutable_datetime', 'required_workers' => 'integer', 'work_minutes' => 'integer', 'duration_minutes' => 'integer'];
    }
}
