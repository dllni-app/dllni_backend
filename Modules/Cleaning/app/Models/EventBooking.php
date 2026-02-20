<?php

declare(strict_types=1);

namespace Modules\Cleaning\Models;

use App\Models\CancellationPolicy;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Modules\Cleaning\Enums\EventBookingStatus;
use Modules\Cleaning\Enums\EventType;

final class EventBooking extends Model
{
    protected $fillable = [
        'customer_id',
        'cancellation_policy_id',
        'billing_policy_id',
        'booking_number',
        'status',
        'event_type',
        'guest_count_min',
        'guest_count_max',
        'gender_preference',
        'suggested_team_size',
        'scheduled_date',
        'scheduled_time',
        'total_hours',
        'base_price',
        'travel_fee',
        'total_price',
        'terms_accepted',
        'cancelled_at',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    public function cancellationPolicy(): BelongsTo
    {
        return $this->belongsTo(CancellationPolicy::class);
    }

    public function billingPolicy(): BelongsTo
    {
        return $this->belongsTo(CleaningBillingPolicy::class, 'billing_policy_id');
    }

    public function services(): BelongsToMany
    {
        return $this->belongsToMany(CleaningService::class, 'event_booking_service')
            ->withPivot(['quantity', 'unit_price', 'total_price'])
            ->withTimestamps();
    }

    public function timeWarnings(): MorphMany
    {
        return $this->morphMany(CleaningTimeWarning::class, 'booking');
    }

    protected function casts(): array
    {
        return [
            'status' => EventBookingStatus::class,
            'event_type' => EventType::class,
            'scheduled_date' => 'date',
            'total_hours' => 'decimal:2',
            'base_price' => 'decimal:2',
            'travel_fee' => 'decimal:2',
            'total_price' => 'decimal:2',
            'terms_accepted' => 'boolean',
            'cancelled_at' => 'datetime',
        ];
    }
}
