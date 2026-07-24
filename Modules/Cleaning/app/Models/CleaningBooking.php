<?php

declare(strict_types=1);

namespace Modules\Cleaning\Models;

use App\Enums\GenderPreference;
use App\Models\BookingReview;
use App\Models\BookingStatusLog;
use App\Models\CancellationPolicy;
use App\Models\CleaningFinancialPenalty;
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
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Modules\Cleaning\Enums\CleaningAssignmentMode;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Enums\CleaningBookingWorkerAssignmentStatus;
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
        'assignment_mode',
        'number_of_workers',
        'gender_preference',
        'work_environment_beneficiary_presence',
        'female_worker_safety_pledge_accepted',
        'female_worker_safety_pledge_accepted_at',
        'female_worker_safety_pledge_version',
        'female_worker_safety_pledge_text',
        'cancellation_policy_id',
        'billing_policy_id',
        'booking_number',
        'status',
        'property_type',
        'property_details',
        'cleaning_services',
        'address_latitude',
        'address_longitude',
        'neighborhood_id',
        'neighborhood_name',
        'estimated_sqm',
        'estimated_hours',
        'scheduled_date',
        'scheduled_time',
        'total_hours',
        'base_price',
        'addons_total',
        'extension_fee_total',
        'travel_fee',
        'travel_distance_km',
        'admin_margin_amount',
        'is_pricing_final',
        'cancellation_fee',
        'total_price',
        'terms_accepted',
        'work_started_at',
        'work_finished_at',
        'worker_completion_message',
        'worker_finished_cleaning_services',
        'worker_finished_property_rooms',
        'customer_completion_rejection_message',
        'completion_rejected_at',
        'started_travel_at',
        'arrived_at',
        'customer_confirmed_at',
        'cancelled_at',
        'cancellation_reason',
        'cancelled_by_role',
        'cancelled_by_user_id',
        'cancelled_by_worker_id',
        'cancellation_offset_minutes',
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

    public function cancelledByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by_user_id');
    }

    public function cancelledByWorker(): BelongsTo
    {
        return $this->belongsTo(Worker::class, 'cancelled_by_worker_id');
    }

    public function financialPenalty(): HasOne
    {
        return $this->hasOne(CleaningFinancialPenalty::class, 'cleaning_booking_id');
    }

    public function neighborhood(): BelongsTo
    {
        return $this->belongsTo(CleaningNeighborhood::class, 'neighborhood_id');
    }

    public function rooms(): HasMany
    {
        return $this->hasMany(CleaningBookingRoom::class, 'cleaning_booking_id');
    }

    public function roomAssignments(): HasMany
    {
        return $this->rooms();
    }

    public function workerAssignments(): HasMany
    {
        return $this->hasMany(CleaningBookingWorkerAssignment::class, 'cleaning_booking_id');
    }

    public function acceptedWorkerAssignments(): HasMany
    {
        return $this->hasMany(CleaningBookingWorkerAssignment::class, 'cleaning_booking_id')
            ->whereIn('status', CleaningBookingWorkerAssignmentStatus::acceptedValues())
            ->orderBy('accepted_at')
            ->orderBy('id');
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

    public function rejections(): HasMany
    {
        return $this->hasMany(CleaningBookingWorkerRejection::class, 'cleaning_booking_id');
    }

    public function ratings(): MorphMany
    {
        return $this->morphMany(WorkerCustomerRating::class, 'booking', 'booking_type', 'booking_id');
    }

    public function casts(): array
    {
        return [
            'status' => CleaningBookingStatus::class,
            'assignment_mode' => CleaningAssignmentMode::class,
            'gender_preference' => GenderPreference::class,
            'number_of_workers' => 'integer',
            'neighborhood_id' => 'integer',
            'property_details' => 'array',
            'cleaning_services' => 'array',
            'worker_finished_cleaning_services' => 'array',
            'worker_finished_property_rooms' => 'array',
            'estimated_sqm' => 'decimal:2',
            'estimated_hours' => 'decimal:2',
            'scheduled_date' => 'date',
            'total_hours' => 'decimal:2',
            'base_price' => 'decimal:2',
            'addons_total' => 'decimal:2',
            'extension_fee_total' => 'decimal:2',
            'travel_fee' => 'decimal:2',
            'travel_distance_km' => 'decimal:3',
            'admin_margin_amount' => 'decimal:2',
            'is_pricing_final' => 'boolean',
            'cancellation_fee' => 'decimal:2',
            'total_price' => 'decimal:2',
            'terms_accepted' => 'boolean',
            'female_worker_safety_pledge_accepted' => 'boolean',
            'female_worker_safety_pledge_accepted_at' => 'datetime',
            'work_started_at' => 'datetime',
            'work_finished_at' => 'datetime',
            'completion_rejected_at' => 'datetime',
            'started_travel_at' => 'datetime',
            'arrived_at' => 'datetime',
            'customer_confirmed_at' => 'datetime',
            'address_latitude' => 'decimal:8',
            'address_longitude' => 'decimal:8',
            'cancelled_at' => 'datetime',
            'cancelled_by_user_id' => 'integer',
            'cancelled_by_worker_id' => 'integer',
            'cancellation_offset_minutes' => 'integer',
        ];
    }

    public function resolvedAssignmentMode(): string
    {
        if ($this->assignment_mode instanceof CleaningAssignmentMode) {
            return $this->assignment_mode->value;
        }

        if (is_string($this->assignment_mode) && $this->assignment_mode !== '') {
            return $this->assignment_mode;
        }

        if ($this->preferred_worker_id !== null && (int) ($this->number_of_workers ?? 1) === 1) {
            return CleaningAssignmentMode::PreferredWorker->value;
        }

        return CleaningAssignmentMode::OpenCount->value;
    }

    public function acceptedWorkerCount(): int
    {
        $count = 0;

        if ($this->relationLoaded('acceptedWorkerAssignments')) {
            $count = $this->acceptedWorkerAssignments->count();
        } elseif ($this->relationLoaded('workerAssignments')) {
            $count = $this->workerAssignments
                ->filter(fn (CleaningBookingWorkerAssignment $assignment): bool => in_array((string) ($assignment->status?->value ?? $assignment->status), CleaningBookingWorkerAssignmentStatus::acceptedValues(), true))
                ->count();
        } else {
            $count = $this->acceptedWorkerAssignments()->count();
        }

        return max(0, (int) $count);
    }

    public function remainingWorkerCount(): int
    {
        return max(0, max(1, (int) ($this->number_of_workers ?? 1)) - $this->acceptedWorkerCount());
    }

    public function isTeamFulfilled(): bool
    {
        return $this->remainingWorkerCount() <= 0;
    }

    public function startApprovedWorkerCount(): int
    {
        $count = 0;

        if ($this->relationLoaded('workerAssignments')) {
            $count = $this->workerAssignments
                ->filter(fn (CleaningBookingWorkerAssignment $assignment): bool => (string) ($assignment->status?->value ?? $assignment->status) === CleaningBookingWorkerAssignmentStatus::StartApproved->value)
                ->count();
        } else {
            $count = $this->workerAssignments()
                ->where('status', CleaningBookingWorkerAssignmentStatus::StartApproved->value)
                ->count();
        }

        return max(0, (int) $count);
    }

    public function notStartApprovedWorkerCount(): int
    {
        return max(0, $this->acceptedWorkerCount() - $this->startApprovedWorkerCount());
    }

    protected static function newFactory(): CleaningBookingFactory
    {
        return CleaningBookingFactory::new();
    }
}
