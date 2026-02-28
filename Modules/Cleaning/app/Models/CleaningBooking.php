<?php

declare(strict_types=1);

namespace Modules\Cleaning\Models;

use App\Models\CancellationPolicy;
use App\Models\BookingReview;
use App\Models\BookingStatusLog;
use App\Models\User;
use App\Models\Worker;
use App\Models\WorkerCustomerRating;
use Database\Factories\CleaningBookingFactory;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Observers\CleaningBookingObserver;
use Modules\Cleaning\Traits\FilterQueries\CleaningBookingFilterQuery;

/**
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Dispute> $disputes
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\SosAlert> $sosAlerts
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\SystemAlert> $systemAlerts
 */
#[ObservedBy([CleaningBookingObserver::class])]
final class CleaningBooking extends Model
{
    use CleaningBookingFilterQuery;
    use HasFactory;

    protected $fillable = [
        'customer_id',
        'worker_id',
        'preferred_worker_id',
        'cancellation_policy_id',
        'billing_policy_id',
        'booking_number',
        'status',
        'property_type',
        'property_details',
        'address_latitude',
        'address_longitude',
        'estimated_sqm',
        'estimated_hours',
        'scheduled_date',
        'scheduled_time',
        'total_hours',
        'base_price',
        'addons_total',
        'travel_fee',
        'cancellation_fee',
        'total_price',
        'terms_accepted',
        'work_started_at',
        'work_finished_at',
        'started_travel_at',
        'arrived_at',
        'customer_confirmed_at',
        'cancelled_at',
        'cancellation_reason',
        'security_code',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    public function worker(): BelongsTo
    {
        return $this->belongsTo(Worker::class);
    }

    public function preferredWorker(): BelongsTo
    {
        return $this->belongsTo(Worker::class, 'preferred_worker_id');
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
        return $this->belongsToMany(CleaningService::class, 'cleaning_booking_service')
            ->withPivot(['quantity', 'unit_price', 'total_price'])
            ->withTimestamps();
    }

    public function addons(): HasMany
    {
        return $this->hasMany(BookingAddon::class);
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

    public function casts(): array
    {
        return [
            'status' => CleaningBookingStatus::class,
            'property_details' => 'array',
            'estimated_sqm' => 'decimal:2',
            'estimated_hours' => 'decimal:2',
            'scheduled_date' => 'date',
            'total_hours' => 'decimal:2',
            'base_price' => 'decimal:2',
            'addons_total' => 'decimal:2',
            'travel_fee' => 'decimal:2',
            'cancellation_fee' => 'decimal:2',
            'total_price' => 'decimal:2',
            'terms_accepted' => 'boolean',
            'work_started_at' => 'datetime',
            'work_finished_at' => 'datetime',
            'started_travel_at' => 'datetime',
            'arrived_at' => 'datetime',
            'customer_confirmed_at' => 'datetime',
            'address_latitude' => 'decimal:8',
            'address_longitude' => 'decimal:8',
            'cancelled_at' => 'datetime',
        ];
    }

    protected static function newFactory(): CleaningBookingFactory
    {
        return CleaningBookingFactory::new();
    }
}
