<?php

declare(strict_types=1);

namespace Modules\Cleaning\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class CleaningNotificationDispatch extends Model
{
    protected $fillable = [
        'cleaning_booking_id',
        'worker_assignment_id',
        'recipient_user_id',
        'canonical_type',
        'dedupe_key',
        'scheduled_at_snapshot',
        'due_at',
        'status',
        'attempts',
        'sent_at',
        'last_error',
    ];

    public function booking(): BelongsTo
    {
        return $this->belongsTo(CleaningBooking::class, 'cleaning_booking_id');
    }

    public function workerAssignment(): BelongsTo
    {
        return $this->belongsTo(CleaningBookingWorkerAssignment::class, 'worker_assignment_id');
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_user_id');
    }

    public function casts(): array
    {
        return [
            'scheduled_at_snapshot' => 'datetime',
            'due_at' => 'datetime',
            'sent_at' => 'datetime',
            'attempts' => 'integer',
        ];
    }
}
