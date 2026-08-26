<?php

declare(strict_types=1);

namespace Modules\Cleaning\Models;

use App\Models\Worker;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Cleaning\Enums\CleaningBookingWorkerAssignmentStatus;
use Modules\Cleaning\Observers\CleaningBookingSessionWorkerAssignmentObserver;

#[ObservedBy([CleaningBookingSessionWorkerAssignmentObserver::class])]
final class CleaningBookingSessionWorkerAssignment extends Model
{
    protected $fillable = [
        'cleaning_booking_session_id',
        'cleaning_booking_worker_assignment_id',
        'worker_id',
        'status',
        'started_travel_at',
        'arrived_at',
        'last_latitude',
        'last_longitude',
        'location_updated_at',
        'start_approved_at',
        'work_started_at',
        'work_finished_at',
        'worker_completion_message',
        'service_share_amount',
        'travel_fee',
        'admin_margin_amount',
        'worker_amount',
        'currency',
    ];

    public function session(): BelongsTo
    {
        return $this->belongsTo(CleaningBookingSession::class, 'cleaning_booking_session_id');
    }

    public function parentAssignment(): BelongsTo
    {
        return $this->belongsTo(CleaningBookingWorkerAssignment::class, 'cleaning_booking_worker_assignment_id');
    }

    public function worker(): BelongsTo
    {
        return $this->belongsTo(Worker::class);
    }

    public function casts(): array
    {
        return [
            'status' => CleaningBookingWorkerAssignmentStatus::class,
            'started_travel_at' => 'datetime',
            'arrived_at' => 'datetime',
            'last_latitude' => 'float',
            'last_longitude' => 'float',
            'location_updated_at' => 'datetime',
            'start_approved_at' => 'datetime',
            'work_started_at' => 'datetime',
            'work_finished_at' => 'datetime',
            'service_share_amount' => 'float',
            'travel_fee' => 'float',
            'admin_margin_amount' => 'float',
            'worker_amount' => 'float',
        ];
    }
}
