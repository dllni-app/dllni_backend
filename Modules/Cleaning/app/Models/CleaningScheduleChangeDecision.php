<?php

declare(strict_types=1);

namespace Modules\Cleaning\Models;

use App\Models\Worker;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class CleaningScheduleChangeDecision extends Model
{
    protected $fillable = [
        'cleaning_schedule_change_request_id', 'cleaning_booking_session_worker_assignment_id',
        'worker_id', 'decision', 'reason', 'decided_at',
    ];

    public function changeRequest(): BelongsTo { return $this->belongsTo(CleaningScheduleChangeRequest::class, 'cleaning_schedule_change_request_id'); }
    public function assignment(): BelongsTo { return $this->belongsTo(CleaningBookingSessionWorkerAssignment::class, 'cleaning_booking_session_worker_assignment_id'); }
    public function worker(): BelongsTo { return $this->belongsTo(Worker::class); }

    public function casts(): array { return ['decided_at' => 'datetime']; }
}
