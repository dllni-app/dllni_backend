<?php

declare(strict_types=1);

namespace Modules\Cleaning\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Cleaning\Enums\CleaningBookingSessionStatus;

final class CleaningBookingSession extends Model
{
    protected $fillable = [
        'cleaning_booking_id',
        'sequence',
        'scheduled_date',
        'scheduled_time',
        'duration_hours',
        'status',
        'base_price',
        'travel_fee',
        'travel_distance_km',
        'admin_margin_amount',
        'extension_fee_total',
        'cancellation_fee',
        'total_price',
        'is_pricing_final',
        'started_travel_at',
        'arrived_at',
        'customer_confirmed_at',
        'work_started_at',
        'work_finished_at',
        'cancelled_at',
        'cancellation_reason',
        'cancelled_by_role',
    ];

    public function booking(): BelongsTo
    {
        return $this->belongsTo(CleaningBooking::class, 'cleaning_booking_id');
    }

    public function workerAssignments(): HasMany
    {
        return $this->hasMany(CleaningBookingSessionWorkerAssignment::class, 'cleaning_booking_session_id');
    }

    public function activeWorkerAssignments(): HasMany
    {
        return $this->workerAssignments()->whereNotIn('status', ['rejected', 'withdrawn', 'cancelled']);
    }

    public function casts(): array
    {
        return [
            'sequence' => 'integer',
            'scheduled_date' => 'date',
            'duration_hours' => 'float',
            'status' => CleaningBookingSessionStatus::class,
            'base_price' => 'float',
            'travel_fee' => 'float',
            'travel_distance_km' => 'float',
            'admin_margin_amount' => 'float',
            'extension_fee_total' => 'float',
            'cancellation_fee' => 'float',
            'total_price' => 'float',
            'is_pricing_final' => 'boolean',
            'started_travel_at' => 'datetime',
            'arrived_at' => 'datetime',
            'customer_confirmed_at' => 'datetime',
            'work_started_at' => 'datetime',
            'work_finished_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function startsAt(): ?CarbonInterface
    {
        if ($this->scheduled_date === null || $this->scheduled_time === null) {
            return null;
        }

        return $this->scheduled_date->copy()->setTimeFromTimeString((string) $this->scheduled_time);
    }

    public function isCancelled(): bool
    {
        return $this->status === CleaningBookingSessionStatus::Cancelled;
    }

    public function isCompleted(): bool
    {
        return $this->status === CleaningBookingSessionStatus::Completed;
    }
}
