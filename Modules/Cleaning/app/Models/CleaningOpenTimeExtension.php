<?php

declare(strict_types=1);

namespace Modules\Cleaning\Models;

use App\Models\User;
use App\Models\Worker;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class CleaningOpenTimeExtension extends Model
{
    protected $fillable = [
        'cleaning_booking_id', 'cleaning_booking_session_id', 'customer_id', 'worker_id',
        'requested_minutes', 'status', 'idempotency_key', 'decision_reason', 'conflict_snapshot', 'decided_at',
    ];

    public function booking(): BelongsTo { return $this->belongsTo(CleaningBooking::class, 'cleaning_booking_id'); }
    public function session(): BelongsTo { return $this->belongsTo(CleaningBookingSession::class, 'cleaning_booking_session_id'); }
    public function customer(): BelongsTo { return $this->belongsTo(User::class); }
    public function worker(): BelongsTo { return $this->belongsTo(Worker::class); }

    public function casts(): array
    {
        return ['requested_minutes' => 'integer', 'conflict_snapshot' => 'array', 'decided_at' => 'datetime'];
    }
}
