<?php

declare(strict_types=1);

namespace Modules\Cleaning\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Pending operational request only. It never releases or pays a worker. */
final class CleaningBookingSessionReplacementRequest extends Model
{
    protected $fillable = [
        'cleaning_booking_session_id',
        'cleaning_booking_session_worker_assignment_id',
        'cleaning_booking_session_worker_attestation_id',
        'cleaning_booking_session_supervisor_assignment_id',
        'requested_by_user_id',
        'status',
        'reason',
        'approved_by_user_id',
        'approved_at',
        'candidate_worker_id',
        'accepted_by_user_id',
        'accepted_at',
        'replacement_worker_assignment_id',
        'reallocated_by_user_id',
        'reallocated_at',
    ];

    public function session(): BelongsTo
    {
        return $this->belongsTo(CleaningBookingSession::class, 'cleaning_booking_session_id');
    }

    protected function casts(): array
    {
        return [
            'approved_at' => 'datetime',
            'accepted_at' => 'datetime',
            'reallocated_at' => 'datetime',
        ];
    }
}
