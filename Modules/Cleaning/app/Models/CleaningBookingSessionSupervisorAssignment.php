<?php

declare(strict_types=1);

namespace Modules\Cleaning\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

final class CleaningBookingSessionSupervisorAssignment extends Model
{
    protected $fillable = [
        'cleaning_booking_session_id',
        'supervisor_user_id',
        'assigned_by_user_id',
        'assigned_at',
        'released_at',
        'reason',
        'compensation_hourly_rate',
        'active_slot',
    ];

    public function session(): BelongsTo
    {
        return $this->belongsTo(CleaningBookingSession::class, 'cleaning_booking_session_id');
    }

    public function supervisor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'supervisor_user_id');
    }

    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by_user_id');
    }

    public function verifications(): HasMany
    {
        return $this->hasMany(CleaningBookingSessionVerification::class, 'cleaning_booking_session_supervisor_assignment_id');
    }

    public function attendance(): HasOne
    {
        return $this->hasOne(CleaningBookingSessionSupervisorAttendance::class, 'cleaning_booking_session_supervisor_assignment_id');
    }

    public function workSegments(): HasMany
    {
        return $this->hasMany(CleaningBookingSessionSupervisorWorkSegment::class, 'cleaning_booking_session_supervisor_assignment_id');
    }

    public function allocatedTaskWorkers(): HasMany
    {
        return $this->hasMany(CleaningBookingSessionTaskWorker::class, 'allocated_by_supervisor_assignment_id');
    }

    protected function casts(): array
    {
        return [
            'assigned_at' => 'datetime',
            'released_at' => 'datetime',
            'compensation_hourly_rate' => 'decimal:2',
            'active_slot' => 'integer',
        ];
    }
}
