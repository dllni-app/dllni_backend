<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DayOfWeek;
use App\Enums\UserModuleType;
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

final class Worker extends Model implements HasMedia
{
    use HasFactory;
    use InteractsWithMedia;
    use LogsActivity;
    use WorkerFilterQuery;

    private const MIN_NEIGHBORHOOD_NAME_LENGTH = 2;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['is_active', 'is_suspended', 'security_deposit_status'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

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
        'default_working_hours',
        'security_deposit_status',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
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

    protected static function booted(): void
    {
        static::updated(function (self $worker): void {
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
        } catch (\Throwable) {
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

    private function minutesFromTime(string $time): ?int
    {
        foreach (['H:i', 'H:i:s'] as $format) {
            try {
                $parsed = Carbon::createFromFormat($format, $time);

                if ($parsed instanceof CarbonInterface) {
                    return $parsed->hour * 60 + $parsed->minute;
                }
            } catch (\Throwable) {
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
}
