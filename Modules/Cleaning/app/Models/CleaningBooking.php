<?php

declare(strict_types=1);

namespace Modules\Cleaning\Models;

use App\Enums\GenderPreference;
use App\Models\BookingReview;
use App\Models\BookingStatusLog;
use App\Models\CancellationPolicy;
use App\Models\User;
use App\Models\Worker;
use App\Models\WorkerCustomerRating;
use Database\Factories\CleaningBookingFactory;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Modules\Cleaning\Enums\CleaningAssignmentMode;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Enums\CleaningBookingWorkerAssignmentStatus;
use Modules\Cleaning\Observers\CleaningBookingObserver;
use Modules\Cleaning\Traits\FilterQueries\CleaningBookingFilterQuery;

/**
 * @property-read EloquentCollection<int, \App\Models\Dispute> $disputes
 * @property-read EloquentCollection<int, \App\Models\SosAlert> $sosAlerts
 * @property-read EloquentCollection<int, \App\Models\SystemAlert> $systemAlerts
 */
#[ObservedBy([CleaningBookingObserver::class])]
final class CleaningBooking extends Model
{
    use CleaningBookingFilterQuery;
    use HasFactory;

    public const PREFERRED_WORKER_REJECTION_DECISION_PENDING = 'pending';

    public const PREFERRED_WORKER_REJECTION_DECISION_CONVERTED_TO_OPEN = 'converted_to_open';

    public const PREFERRED_WORKER_REJECTION_DECISION_CANCELLED = 'cancelled';

    public const WORKER_SCOPE_ANY = 'any';

    public const WORKER_SCOPE_SPECIFIC = 'specific';

    protected $fillable = [
        'customer_id',
        'worker_id',
        'preferred_worker_id',
        'assignment_mode',
        'worker_scope',
        'specific_worker_ids',
        'converted_from_preferred_worker',
        'converted_from_preferred_worker_at',
        'preferred_worker_rejection_decision_status',
        'preferred_worker_rejected_at',
        'preferred_worker_rejection_worker_id',
        'preferred_worker_rejection_decided_at',
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
        'booking_kind',
        'capability_schema_version',
        'property_type',
        'cleaning_event_type_id',
        'property_details',
        'event_dynamic_answers',
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
        'open_time_hourly_rate',
        'open_time_minimum_minutes',
        'open_time_rounding_minutes',
        'open_time_expected_max_minutes',
        'open_time_hard_max_minutes',
        'open_time_warning_minutes',
        'open_time_extension_options',
        'open_time_ceiling_ends_at',
        'open_time_end_requested_at',
        'open_time_end_status',
        'open_time_terminated_at',
        'open_time_terminated_by_id',
        'open_time_termination_reason',
        'open_time_actual_minutes',
        'open_time_billable_minutes',
        'open_time_final_amount',
        'open_time_finalized_at',
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
        'recurring_paused_at',
        'recurring_pause_reason',
        'cancelled_at',
        'cancellation_reason',
        'cancelled_by_role',
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

    public function neighborhood(): BelongsTo
    {
        return $this->belongsTo(CleaningNeighborhood::class, 'neighborhood_id');
    }

    public function rooms(): HasMany
    {
        return $this->hasMany(CleaningBookingRoom::class, 'cleaning_booking_id');
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(CleaningBookingSession::class, 'cleaning_booking_id')
            ->orderBy('sequence');
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

    public function materials(): HasMany
    {
        return $this->hasMany(CleaningBookingMaterial::class, 'cleaning_booking_id');
    }

    public function specialServices(): HasMany
    {
        return $this->hasMany(CleaningBookingSpecialService::class, 'cleaning_booking_id');
    }

    public function materialKit(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(CleaningBookingMaterialKit::class, 'cleaning_booking_id');
    }

    public function eventType(): BelongsTo
    {
        return $this->belongsTo(CleaningEventType::class, 'cleaning_event_type_id');
    }

    public function openTimeExtensions(): HasMany
    {
        return $this->hasMany(CleaningOpenTimeExtension::class, 'cleaning_booking_id');
    }

    public function scheduleChangeRequests(): HasMany
    {
        return $this->hasMany(CleaningScheduleChangeRequest::class, 'cleaning_booking_id');
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
            'specific_worker_ids' => 'array',
            'converted_from_preferred_worker' => 'boolean',
            'converted_from_preferred_worker_at' => 'datetime',
            'preferred_worker_rejection_worker_id' => 'integer',
            'preferred_worker_rejected_at' => 'datetime',
            'preferred_worker_rejection_decided_at' => 'datetime',
            'gender_preference' => GenderPreference::class,
            'number_of_workers' => 'integer',
            'neighborhood_id' => 'integer',
            'property_details' => 'array',
            'event_dynamic_answers' => 'array',
            'cleaning_services' => 'array',
            'worker_finished_cleaning_services' => 'array',
            'worker_finished_property_rooms' => 'array',
            'estimated_sqm' => 'decimal:2',
            'estimated_hours' => 'decimal:2',
            'scheduled_date' => 'date',
            'total_hours' => 'decimal:2',
            'base_price' => 'float',
            'open_time_hourly_rate' => 'float',
            'open_time_minimum_minutes' => 'integer',
            'open_time_rounding_minutes' => 'integer',
            'open_time_expected_max_minutes' => 'integer',
            'open_time_hard_max_minutes' => 'integer',
            'open_time_warning_minutes' => 'integer',
            'open_time_extension_options' => 'array',
            'open_time_ceiling_ends_at' => 'datetime',
            'open_time_end_requested_at' => 'datetime',
            'open_time_terminated_at' => 'datetime',
            'open_time_actual_minutes' => 'integer',
            'open_time_billable_minutes' => 'integer',
            'open_time_final_amount' => 'float',
            'open_time_finalized_at' => 'datetime',
            'addons_total' => 'float',
            'extension_fee_total' => 'float',
            'travel_fee' => 'float',
            'travel_distance_km' => 'decimal:3',
            'admin_margin_amount' => 'float',
            'is_pricing_final' => 'boolean',
            'cancellation_fee' => 'float',
            'total_price' => 'float',
            'terms_accepted' => 'boolean',
            'female_worker_safety_pledge_accepted' => 'boolean',
            'female_worker_safety_pledge_accepted_at' => 'datetime',
            'work_started_at' => 'datetime',
            'work_finished_at' => 'datetime',
            'completion_rejected_at' => 'datetime',
            'started_travel_at' => 'datetime',
            'arrived_at' => 'datetime',
            'customer_confirmed_at' => 'datetime',
            'recurring_paused_at' => 'datetime',
            'address_latitude' => 'decimal:8',
            'address_longitude' => 'decimal:8',
            'cancelled_at' => 'datetime',
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

    public function resolvedWorkerScope(): string
    {
        $explicit = mb_strtolower(mb_trim((string) ($this->worker_scope ?? '')));
        if (in_array($explicit, [self::WORKER_SCOPE_ANY, self::WORKER_SCOPE_SPECIFIC], true)) {
            return $explicit;
        }

        return $this->resolvedAssignmentMode() === CleaningAssignmentMode::PreferredWorker->value
            && $this->preferred_worker_id !== null
                ? self::WORKER_SCOPE_SPECIFIC
                : self::WORKER_SCOPE_ANY;
    }

    /** @return array<int, int> */
    public function specificWorkerIds(): array
    {
        $ids = [];
        foreach (is_array($this->specific_worker_ids) ? $this->specific_worker_ids : [] as $value) {
            if (! is_numeric($value)) {
                continue;
            }

            $id = (int) $value;
            if ($id > 0 && ! in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }

        if (
            $ids === []
            && $this->resolvedWorkerScope() === self::WORKER_SCOPE_SPECIFIC
            && $this->preferred_worker_id !== null
        ) {
            $ids[] = (int) $this->preferred_worker_id;
        }

        return $ids;
    }

    public function requiresPreferredWorkerRejectionDecision(): bool
    {
        $status = $this->status instanceof CleaningBookingStatus
            ? $this->status->value
            : (string) $this->status;

        return $status === CleaningBookingStatus::Pending->value
            && (string) ($this->preferred_worker_rejection_decision_status ?? '') === self::PREFERRED_WORKER_REJECTION_DECISION_PENDING;
    }

    public function dashboardKindLabel(): string
    {
        if ($this->isEventAssistanceBooking()) {
            return 'مساعدة مناسبة';
        }

        return $this->isDeepCleaningBooking() ? 'تنظيف عميق' : 'تنظيف عادي';
    }

    public function dashboardKindColor(): string
    {
        if ($this->isEventAssistanceBooking()) {
            return 'warning';
        }

        return $this->isDeepCleaningBooking() ? 'purple' : 'info';
    }

    public function isEventAssistanceBooking(): bool
    {
        return $this->property_type === 'event_assistance';
    }

    public function isDeepCleaningBooking(): bool
    {
        $details = is_array($this->property_details) ? $this->property_details : [];

        return ($details['cleaning_mode'] ?? null) === 'deep';
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
                ->filter(fn (CleaningBookingWorkerAssignment $assignment): bool => $assignment->start_approved_at !== null)
                ->count();
        } else {
            $count = $this->workerAssignments()
                ->whereNotNull('start_approved_at')
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
