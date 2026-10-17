<?php

declare(strict_types=1);

namespace Modules\Cleaning\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class CleaningBookingSessionTaskWorkerSegment extends Model
{
    protected $fillable = [
        'cleaning_booking_session_task_worker_id',
        'started_at',
        'ended_at',
        'end_reason',
        'recorded_by_user_id',
        'active_slot',
    ];

    public function taskWorker(): BelongsTo
    {
        return $this->belongsTo(CleaningBookingSessionTaskWorker::class, 'cleaning_booking_session_task_worker_id');
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_user_id');
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
