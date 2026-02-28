<?php

declare(strict_types=1);

namespace Modules\Cleaning\Models;

use App\Models\CancellationPolicy;
use App\Models\BookingReview;
use App\Models\BookingStatusLog;
use App\Models\User;
use App\Models\WorkerCustomerRating;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Database\Factories\EventBookingFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Modules\Cleaning\Enums\EventBookingStatus;
use Modules\Cleaning\Enums\EventType;
use Modules\Cleaning\Observers\EventBookingObserver;
use Modules\Cleaning\Traits\FilterQueries\EventBookingFilterQuery;

/**
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Dispute> $disputes
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\SosAlert> $sosAlerts
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\SystemAlert> $systemAlerts
 */
#[ObservedBy([EventBookingObserver::class])]
final class EventBooking extends Model
{
    use EventBookingFilterQuery;
    use HasFactory;

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

    public function disputes(): MorphMany
    {
        return $this->morphMany(\App\Models\Dispute::class, 'booking', 'booking_type', 'booking_id');
    }

    public function sosAlerts(): MorphMany
    {
        return $this->morphMany(\App\Models\SosAlert::class, 'booking', 'booking_type', 'booking_id');
    }

    public function systemAlerts(): MorphMany
    {
        return $this->morphMany(\App\Models\SystemAlert::class, 'booking', 'booking_type', 'booking_id');
    }

    public function statusLogs(): MorphMany
    {
        return $this->morphMany(BookingStatusLog::class, 'booking', 'booking_type', 'booking_id');
    }

    public function reviews(): MorphMany
    {
        return $this->morphMany(BookingReview::class, 'booking', 'booking_type', 'booking_id');
    }

    public function ratings(): MorphMany
    {
        return $this->morphMany(WorkerCustomerRating::class, 'booking', 'booking_type', 'booking_id');
    }

    protected static function newFactory(): EventBookingFactory
    {
        return EventBookingFactory::new();
    }

    public function casts(): array
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
