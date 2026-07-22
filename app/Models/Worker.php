<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DayOfWeek;
use App\Enums\UserModuleType;
use App\Enums\WorkerHomeLocationStatus;
use App\Enums\WorkerPreferredWorkType;
use App\Traits\FilterQueries\WorkerFilterQuery;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningNeighborhood;
use Modules\Cleaning\Support\CleaningNeighborhoodNameNormalizer;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Throwable;

final class Worker extends Model implements HasMedia
{
    use HasFactory;
    use InteractsWithMedia;
    use LogsActivity;
    use WorkerFilterQuery;

    private const MIN_NEIGHBORHOOD_NAME_LENGTH = 2;

    protected $fillable = [
        'user_id',
        'first_name',
        'gender',
        'birthday',
        'preferred_work_type',
        'bio',
        'average_rating',
        'total_completed_jobs',
        'trust_score',
        'acceptance_rate',
        'cancellation_rate',
        'open_disputes_count',
        'is_active',
        'is_suspended',
        'is_verified',
        'is_featured',
        'featured_until',
        'suspended_until',
        'home_address',
        'home_latitude',
        'home_longitude',
        'pending_home_address',
        'pending_home_latitude',
        'pending_home_longitude',
        'home_location_status',
        'home_location_rejection_reason',
        'default_working_hours',
        'security_deposit_status',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['is_active', 'is_suspended', 'security_deposit_status'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Workers who are fully active: enabled, not suspended, and not financially restricted.
     */
    public function scopeActiveAvailable(Builder $query): Builder
    {
        return $query->where('is_active', true)
            ->where('is_suspended', false)
            ->where(function (Builder $status): void {
                $status->whereNull('security_deposit_status')
                    ->orWhere('security_deposit_status', 'active');
            });
    }

    /**
     * Workers who are restricted from taking new work: deactivated, suspended,
     * or blocked by their deposit balance / commission utilization.
     */
    public function scopeRestricted(Builder $query): Builder
    {
        return $query->where(function (Builder $restricted): void {
            $restricted->where('is_active', false)
                ->orWhere('is_suspended', true)
                ->orWhere(function (Builder $status): void {
                    $status->whereNotNull('security_deposit_status')
                        ->where('security_deposit_status', '!=', 'active');
                });
        });
    }

    public function zones(): HasMany
    {
        return $this->hasMany(WorkerZone::class);
    }

    public function scopeCoversNeighborhood(Builder $query, int $neighborhoodId): Builder
    {
        $neighborhoodNames = self::coverageNeighborhoodNames($neighborhoodId);

        return $query->where(function (Builder $workers) use ($neighborhoodId, $neighborhoodNames): void {
            $workers->whereHas('zones', function (Builder $zones) use ($neighborhoodId): void {
                $zones->where('worker_zones.is_active', true)
                    ->where('worker_zones.neighborhood_id', $neighborhoodId);
            });

            if ($neighborhoodNames === []) {
                return;
            }

            $workers->orWhere(function (Builder $addressQuery) use ($neighborhoodNames): void {
                foreach ($neighborhoodNames as $name) {
                    $addressQuery->orWhere('home_address', 'like', '%'.self::escapeLike($name).'%');
                }
            });
        });
    }

    public function availability(): HasMany
    {
        return $this->hasMany(WorkerAvailability::class);
    }

    public function trustLogs(): HasMany
    {
        return $this->hasMany(WorkerTrustLog::class);
    }

    public function cleaningBookings(): HasMany
    {
        return $this->hasMany(CleaningBooking::class);
    }

    public function customerRatings(): HasMany
    {
        return $this->hasMany(WorkerCustomerRating::class);
    }

    public function deposit()
    {
        return $this->hasOne(CleaningWorkerDeposit::class);
    }

    public function depositTransactions(): HasMany
    {
        return $this->hasMany(CleaningDepositTransaction::class);
    }

    public function casts(): array
    {
        return [
            'average_rating' => 'decimal:2',
            'acceptance_rate' => 'decimal:2',
            'cancellation_rate' => 'decimal:2',
            'birthday' => 'date',
            'preferred_work_type' => WorkerPreferredWorkType::class,
            'home_latitude' => 'decimal:8',
            'home_longitude' => 'decimal:8',
            'pending_home_latitude' => 'decimal:8',
            'pending_home_longitude' => 'decimal:8',
            'home_location_status' => WorkerHomeLocationStatus::class,
            'is_active' => 'boolean',
            'is_suspended' => 'boolean',
            'is_verified' => 'boolean',
            'is_featured' => 'boolean',
            'featured_until' => 'datetime',
            'suspended_until' => 'datetime',
            'default_working_hours' => 'array',
            'security_deposit_status' => 'string',
        ];
    }

    /**
     * @return array<string, array{available: bool, data: array<int, array<string, string>>}>
     */
    public function getNormalizedDefaultWorkingHours(): array
    {
        $raw = $this->default_working_hours ?? [];
        $normalized = [];
        foreach (DayOfWeek::values() as $day) {
            $normalized[$day] = $this->normalizeDayWorkingHours($raw[$day] ?? null);
        }

        return $normalized;
    }

    public function isAvailableForBooking(?CleaningBooking $booking): bool
    {
        if (! $booking instanceof CleaningBooking) {
            return false;
        }

        $dateTime = $this->bookingDateTime($booking);

        if (! $dateTime instanceof CarbonInterface) {
            return false;
        }

        return $this->isAvailableAt($dateTime);
    }

    public function hasActiveCoverageForNeighborhood(?int $neighborhoodId): bool
    {
        return $neighborhoodId !== null;
    }

    public function isAvailableAt(CarbonInterface $dateTime): bool
    {
        $dayKey = mb_strtolower($dateTime->format('l'));
        $dayHours = $this->getNormalizedDefaultWorkingHours()[$dayKey] ?? ['available' => false, 'data' => []];

        if (! (bool) ($dayHours['available'] ?? false)) {
            return false;
        }

        $targetMinutes = $this->minutesFromTime($dateTime->format('H:i'));
        if ($targetMinutes === null) {
            return false;
        }

        foreach ($dayHours['data'] as $period) {
            if (! is_array($period)) {
                continue;
            }

            [$from, $to] = $this->timeRangeFromPeriod($period);

            if ($from === null || $to === null) {
                continue;
            }

            if ($this->isWithinWorkingPeriod($targetMinutes, $from, $to)) {
                return true;
            }
        }

        return false;
    }

    protected static function booted(): void
    {
        self::updated(function (self $worker): void {
            if (! $worker->wasChanged('first_name')) {
                return;
            }

            $user = $worker->user;
            if (! $user || $user->module_type !== UserModuleType::CleaningWorker) {
                return;
            }

            if ($user->name === $worker->first_name) {
                return;
            }

            $user->forceFill([
                'name' => $worker->first_name,
            ])->saveQuietly();
        });
    }

    /**
     * @return array<int, string>
     */
    private static function coverageNeighborhoodNames(int $neighborhoodId): array
    {
        $neighborhood = CleaningNeighborhood::query()->find($neighborhoodId);
        if (! $neighborhood instanceof CleaningNeighborhood) {
            return [];
        }

        $aliases = is_array($neighborhood->aliases) ? $neighborhood->aliases : [];
        $names = array_merge([
            $neighborhood->name_ar,
            $neighborhood->name_en,
            $neighborhood->normalized_name,
        ], $aliases);

        $normalized = [];
        foreach ($names as $name) {
            if (! is_string($name)) {
                continue;
            }

            $name = CleaningNeighborhoodNameNormalizer::repairText($name);
            if (mb_strlen($name) < self::MIN_NEIGHBORHOOD_NAME_LENGTH || in_array($name, $normalized, true)) {
                continue;
            }

            $normalized[] = $name;
        }

        return $normalized;
    }

    private static function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    /**
     * @param  array<string, mixed>|array<int, array{from: string, to: string}>|bool|null  $value
     * @return array{available: bool, data: array<int, array<string, string>>}
     */
    private function normalizeDayWorkingHours(mixed $value): array
    {
        if ($value === null || $value === false) {
            return ['available' => false, 'data' => []];
        }

        if (isset($value['available'], $value['data']) && is_array($value['data'])) {
            return [
                'available' => (bool) $value['available'],
                'data' => $value['data'],
            ];
        }

        if (is_array($value)) {
            $data = [];
            foreach ($value as $period) {
                if (is_array($period) && isset($period['from'], $period['to'])) {
                    $data[] = [$period['from'] => $period['to']];
                }
            }

            return [
                'available' => count($data) > 0,
                'data' => $data,
            ];
        }

        return ['available' => false, 'data' => []];
    }

    private function bookingDateTime(CleaningBooking $booking): ?CarbonInterface
    {
        if ($booking->scheduled_date === null || $booking->scheduled_time === null) {
            return null;
        }

        $scheduledDate = $booking->scheduled_date instanceof CarbonInterface
            ? $booking->scheduled_date->toDateString()
            : (string) $booking->scheduled_date;
        $scheduledTime = mb_trim((string) $booking->scheduled_time);

        if ($scheduledDate === '' || $scheduledTime === '') {
            return null;
        }

        try {
            return Carbon::parse($scheduledDate.' '.$scheduledTime, config('app.timezone'));
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string, string>  $period
     * @return array{0: ?string, 1: ?string}
     */
    private function timeRangeFromPeriod(array $period): array
    {
        if (isset($period['from'], $period['to']) && is_string($period['from']) && is_string($period['to'])) {
            return [$period['from'], $period['to']];
        }

        $from = array_key_first($period);
        if (! is_string($from)) {
            return [null, null];
        }

        $to = $period[$from] ?? null;
        if (! is_string($to)) {
            return [null, null];
        }

        return [$from, $to];
    }

    private function isWithinWorkingPeriod(int $targetMinutes, string $from, string $to): bool
    {
        $startMinutes = $this->minutesFromTime($from);
        $endMinutes = $this->minutesFromTime($to);

        if ($startMinutes === null || $endMinutes === null) {
            return false;
        }

        if ($startMinutes === $endMinutes) {
            return true;
        }

        if ($endMinutes > $startMinutes) {
            return $targetMinutes >= $startMinutes && $targetMinutes <= $endMinutes;
        }

        return $targetMinutes >= $startMinutes || $targetMinutes <= $endMinutes;
    }

    public function hasPendingHomeLocation(): bool
    {
        return $this->home_location_status === WorkerHomeLocationStatus::Pending
            && (
                $this->pending_home_address !== null
                || $this->pending_home_latitude !== null
                || $this->pending_home_longitude !== null
            );
    }

    /**
     * @param  array{homeAddress?: string|null, homeLatitude?: float|int|string|null, homeLongitude?: float|int|string|null}  $input
     * @return array<string, mixed>
     */
    public function pendingHomeLocationUpdatesFrom(array $input): array
    {
        $nextAddress = array_key_exists('homeAddress', $input)
            ? $input['homeAddress']
            : $this->home_address;
        $nextLatitude = array_key_exists('homeLatitude', $input)
            ? $input['homeLatitude']
            : $this->home_latitude;
        $nextLongitude = array_key_exists('homeLongitude', $input)
            ? $input['homeLongitude']
            : $this->home_longitude;

        if (! $this->homeLocationChanged($nextAddress, $nextLatitude, $nextLongitude)) {
            if (! $this->hasPendingHomeLocation()) {
                return [];
            }

            return [
                'pending_home_address' => null,
                'pending_home_latitude' => null,
                'pending_home_longitude' => null,
                'home_location_status' => WorkerHomeLocationStatus::Approved,
                'home_location_rejection_reason' => null,
            ];
        }

        return [
            'pending_home_address' => $nextAddress,
            'pending_home_latitude' => $nextLatitude,
            'pending_home_longitude' => $nextLongitude,
            'home_location_status' => WorkerHomeLocationStatus::Pending,
            'home_location_rejection_reason' => null,
        ];
    }

    public function approvePendingHomeLocation(): void
    {
        $this->forceFill([
            'home_address' => $this->pending_home_address,
            'home_latitude' => $this->pending_home_latitude,
            'home_longitude' => $this->pending_home_longitude,
            'pending_home_address' => null,
            'pending_home_latitude' => null,
            'pending_home_longitude' => null,
            'home_location_status' => WorkerHomeLocationStatus::Approved,
            'home_location_rejection_reason' => null,
        ])->save();
    }

    public function rejectPendingHomeLocation(string $reason): void
    {
        $this->forceFill([
            'pending_home_address' => null,
            'pending_home_latitude' => null,
            'pending_home_longitude' => null,
            'home_location_status' => WorkerHomeLocationStatus::Rejected,
            'home_location_rejection_reason' => $reason,
        ])->save();
    }

    private function homeLocationChanged(mixed $address, mixed $latitude, mixed $longitude): bool
    {
        $normalizedAddress = $address === null ? null : mb_trim((string) $address);
        $currentAddress = $this->home_address === null ? null : mb_trim((string) $this->home_address);

        if ($normalizedAddress !== $currentAddress) {
            return true;
        }

        return $this->coordinateChanged($latitude, $this->home_latitude)
            || $this->coordinateChanged($longitude, $this->home_longitude);
    }

    private function coordinateChanged(mixed $next, mixed $current): bool
    {
        if ($next === null && $current === null) {
            return false;
        }

        if ($next === null || $current === null) {
            return true;
        }

        return abs((float) $next - (float) $current) > 0.0000001;
    }

    private function minutesFromTime(string $time): ?int
    {
        foreach (['H:i', 'H:i:s'] as $format) {
            try {
                $parsed = Carbon::createFromFormat($format, $time);

                if ($parsed instanceof CarbonInterface) {
                    return $parsed->hour * 60 + $parsed->minute;
                }
            } catch (Throwable) {
                continue;
            }
        }

        return null;
    }

    private function homeAddressMatchesNeighborhood(int $neighborhoodId): bool
    {
        $homeAddress = CleaningNeighborhoodNameNormalizer::normalize((string) $this->home_address);
        if ($homeAddress === '') {
            return false;
        }

        foreach (self::coverageNeighborhoodNames($neighborhoodId) as $name) {
            $normalizedName = CleaningNeighborhoodNameNormalizer::normalize($name);

            if (mb_strlen($normalizedName) < self::MIN_NEIGHBORHOOD_NAME_LENGTH) {
                continue;
            }

            if (str_contains($homeAddress, $normalizedName)) {
                return true;
            }
        }

        return false;
    }
}
