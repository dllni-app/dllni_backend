<?php

declare(strict_types=1);

namespace Modules\Cleaning\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class CleaningBookingSessionTask extends Model
{
    protected $fillable = [
        'cleaning_booking_session_id',
        'task_key',
        'source_kind',
        'cleaning_room_schedule_id',
        'cleaning_booking_room_period_id',
        'required_workers',
        'provenance_snapshot',
        'status',
        'version',
    ];

    public function session(): BelongsTo
    {
        return $this->belongsTo(CleaningBookingSession::class, 'cleaning_booking_session_id');
    }

    public function roomSchedule(): BelongsTo
    {
        return $this->belongsTo(CleaningRoomSchedule::class, 'cleaning_room_schedule_id');
    }

    public function roomPeriod(): BelongsTo
    {
        return $this->belongsTo(CleaningBookingRoomPeriod::class, 'cleaning_booking_room_period_id');
    }

    public function workers(): HasMany
    {
        return $this->hasMany(CleaningBookingSessionTaskWorker::class, 'cleaning_booking_session_task_id');
    }

    public function supervisorWorkSegments(): HasMany
    {
        return $this->hasMany(CleaningBookingSessionSupervisorWorkSegment::class, 'initiating_task_id');
    }

    protected function casts(): array
    {
        return [
            'required_workers' => 'integer',
            'provenance_snapshot' => 'array',
            'version' => 'integer',
        ];
    }
}
