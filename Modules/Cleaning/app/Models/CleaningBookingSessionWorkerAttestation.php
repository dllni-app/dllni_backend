<?php

declare(strict_types=1);

namespace Modules\Cleaning\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class CleaningBookingSessionWorkerAttestation extends Model
{
    protected $fillable = [
        'cleaning_booking_session_id',
        'cleaning_booking_session_worker_assignment_id',
        'cleaning_booking_session_supervisor_assignment_id',
        'recorded_by_user_id',
        'outcome',
        'note',
        'certified_at',
    ];

    public function session(): BelongsTo
    {
        return $this->belongsTo(CleaningBookingSession::class, 'cleaning_booking_session_id');
    }

    public function workerAssignment(): BelongsTo
    {
        return $this->belongsTo(CleaningBookingSessionWorkerAssignment::class, 'cleaning_booking_session_worker_assignment_id');
    }

    public function supervisorAssignment(): BelongsTo
    {
        return $this->belongsTo(CleaningBookingSessionSupervisorAssignment::class, 'cleaning_booking_session_supervisor_assignment_id');
    }

    protected function casts(): array
    {
        return ['certified_at' => 'datetime'];
    }
}
