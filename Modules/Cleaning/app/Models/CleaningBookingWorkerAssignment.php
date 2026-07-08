<?php

declare(strict_types=1);

namespace Modules\Cleaning\Models;

use App\Models\Worker;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Cleaning\Enums\CleaningBookingWorkerAssignmentStatus;

final class CleaningBookingWorkerAssignment extends Model
{
    use HasFactory;

    protected $fillable = [
        'cleaning_booking_id',
        'worker_id',
        'status',
        'accepted_at',
        'started_travel_at',
        'arrived_at',
        'start_approved_at',
        'work_started_at',
        'work_finished_at',
        'worker_completion_message',
        'room_count',
        'rooms_weight',
        'service_share_amount',
        'travel_fee',
        'admin_margin_amount',
        'worker_amount',
        'currency',
    ];

    public function booking(): BelongsTo
    {
        return $this->belongsTo(CleaningBooking::class, 'cleaning_booking_id');
    }

    public function worker(): BelongsTo
    {
        return $this->belongsTo(Worker::class);
    }

    public function casts(): array
    {
        return [
            'status' => CleaningBookingWorkerAssignmentStatus::class,
            'accepted_at' => 'datetime',
            'started_travel_at' => 'datetime',
            'arrived_at' => 'datetime',
            'start_approved_at' => 'datetime',
            'work_started_at' => 'datetime',
            'work_finished_at' => 'datetime',
            'room_count' => 'integer',
            'rooms_weight' => 'decimal:2',
            'service_share_amount' => 'decimal:2',
            'travel_fee' => 'decimal:2',
            'admin_margin_amount' => 'decimal:2',
            'worker_amount' => 'decimal:2',
        ];
    }
}
