<?php

declare(strict_types=1);

namespace Modules\Cleaning\Models;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Cleaning\Enums\CleaningBookingSessionCoverageStatus;
use Modules\Cleaning\Enums\CleaningBookingSessionStatus;
use Modules\Cleaning\Enums\CleaningBookingWorkerAssignmentStatus;
use Throwable;

final class CleaningBookingSession extends Model
{
    protected $fillable = [
        'cleaning_booking_id',
        'sequence',
        'session_type',
        'calculation_mode',
        'scheduled_date',
        'scheduled_time',
        'duration_hours',
        'required_workers',
        'coverage_status',
        'status',
        'base_price',
        'addons_total',
        'materials_total',
        'special_services_total',
        'travel_fee',
        'travel_distance_km',
        'admin_margin_amount',
        'extension_fee_total',
        'cancellation_fee',
        'total_price',
        'is_pricing_final',
        'pricing_snapshot',
        'version',
        'started_travel_at',
        'arrived_at',
        'customer_confirmed_at',
        'work_started_at',
        'work_finished_at',
        'skipped_at',
        'skip_source',
        'skip_reason',
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
        return $this->workerAssignments()
            ->whereIn('status', CleaningBookingWorkerAssignmentStatus::activeValues());
    }

    public function acceptedWorkerAssignments(): HasMany
    {
        return $this->workerAssignments()
            ->whereIn('status', CleaningBookingWorkerAssignmentStatus::acceptedValues());
    }

    public function casts(): array
    {
        return [
            'sequence' => 'integer',
            'scheduled_date' => 'date',
            'duration_hours' => 'float',
            'required_workers' => 'integer',
            'coverage_status' => CleaningBookingSessionCoverageStatus::class,
            'status' => CleaningBookingSessionStatus::class,
            'base_price' => 'float',
            'addons_total' => 'float',
            'materials_total' => 'float',
            'special_services_total' => 'float',
            'travel_fee' => 'float',
            'travel_distance_km' => 'float',
            'admin_margin_amount' => 'float',
            'extension_fee_total' => 'float',
            'cancellation_fee' => 'float',
            'total_price' => 'float',
            'is_pricing_final' => 'boolean',
            'pricing_snapshot' => 'array',
            'version' => 'integer',
            'started_travel_at' => 'datetime',
            'arrived_at' => 'datetime',
            'customer_confirmed_at' => 'datetime',
            'work_started_at' => 'datetime',
            'work_finished_at' => 'datetime',
            'skipped_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function startsAt(): ?CarbonImmutable
    {
        $date = $this->scheduled_date instanceof CarbonInterface
            ? $this->scheduled_date->toDateString()
            : mb_trim((string) $this->scheduled_date);
        $time = mb_trim((string) $this->scheduled_time);

        if ($date === '' || $time === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse("{$date} {$time}", config('app.timezone'));
        } catch (Throwable) {
            return null;
        }
    }

    public function endsAt(): ?CarbonImmutable
    {
        $start = $this->startsAt();

        if ($start === null) {
            return null;
        }

        $minutes = max(1, (int) ceil(max((float) $this->duration_hours, 0.01) * 60));

        return $start->addMinutes($minutes);
    }

    public function requiredWorkerCount(): int
    {
        return max(1, (int) ($this->required_workers ?? 1));
    }

    public function acceptedWorkerCount(): int
    {
        if ($this->relationLoaded('workerAssignments')) {
            return $this->workerAssignments
                ->filter(fn (CleaningBookingSessionWorkerAssignment $assignment): bool => in_array(
                    (string) ($assignment->status?->value ?? $assignment->status),
                    CleaningBookingWorkerAssignmentStatus::acceptedValues(),
                    true,
                ))
                ->count();
        }

        return $this->acceptedWorkerAssignments()->count();
    }

    public function remainingWorkerCount(): int
    {
        return max(0, $this->requiredWorkerCount() - $this->acceptedWorkerCount());
    }

    public function isFullyCovered(): bool
    {
        return $this->remainingWorkerCount() === 0;
    }

    public function isTerminal(): bool
    {
        $status = $this->status instanceof CleaningBookingSessionStatus
            ? $this->status
            : CleaningBookingSessionStatus::tryFrom((string) $this->status);

        return $status?->isTerminal() ?? false;
    }
}
