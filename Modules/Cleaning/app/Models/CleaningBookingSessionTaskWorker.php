<?php

declare(strict_types=1);

namespace Modules\Cleaning\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class CleaningBookingSessionTaskWorker extends Model
{
    protected $fillable = [
        'cleaning_booking_session_task_id',
        'cleaning_booking_session_worker_assignment_id',
        'allocated_by_supervisor_assignment_id',
        'allocated_by_user_id',
        'membership_source',
        'allocated_at',
        'released_at',
        'release_reason',
        'active_slot',
    ];

    public function task(): BelongsTo
    {
        return $this->belongsTo(CleaningBookingSessionTask::class, 'cleaning_booking_session_task_id');
    }

    public function workerAssignment(): BelongsTo
    {
        return $this->belongsTo(CleaningBookingSessionWorkerAssignment::class, 'cleaning_booking_session_worker_assignment_id');
    }

    public function allocatedBySupervisorAssignment(): BelongsTo
    {
        return $this->belongsTo(CleaningBookingSessionSupervisorAssignment::class, 'allocated_by_supervisor_assignment_id');
    }

    public function allocatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'allocated_by_user_id');
    }

    public function segments(): HasMany
    {
        return $this->hasMany(CleaningBookingSessionTaskWorkerSegment::class, 'cleaning_booking_session_task_worker_id');
    }

    protected function casts(): array
    {
        return [
            'allocated_at' => 'datetime',
            'released_at' => 'datetime',
            'active_slot' => 'integer',
        ];
    }
}
