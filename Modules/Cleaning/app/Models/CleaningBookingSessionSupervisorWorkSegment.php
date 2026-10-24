<?php

declare(strict_types=1);

namespace Modules\Cleaning\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class CleaningBookingSessionSupervisorWorkSegment extends Model
{
    protected $fillable = [
        'cleaning_booking_session_id',
        'cleaning_booking_session_supervisor_assignment_id',
        'initiating_task_id',
        'started_at',
        'ended_at',
        'end_reason',
        'ended_by_user_id',
        'active_slot',
    ];

    public function session(): BelongsTo
    {
        return $this->belongsTo(CleaningBookingSession::class, 'cleaning_booking_session_id');
    }

    public function supervisorAssignment(): BelongsTo
    {
        return $this->belongsTo(CleaningBookingSessionSupervisorAssignment::class, 'cleaning_booking_session_supervisor_assignment_id');
    }

    public function initiatingTask(): BelongsTo
    {
        return $this->belongsTo(CleaningBookingSessionTask::class, 'initiating_task_id');
    }

    public function endedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ended_by_user_id');
    }

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'active_slot' => 'integer',
        ];
    }
}
