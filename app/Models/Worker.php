<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DayOfWeek;
use App\Traits\FilterQueries\WorkerFilterQuery;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Cleaning\Models\CleaningBooking;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

final class Worker extends Model implements HasMedia
{
    use HasFactory;
    use InteractsWithMedia;
    use WorkerFilterQuery;

    protected $fillable = [
        'user_id',
        'first_name',
        'gender',
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
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function zones(): HasMany
    {
        return $this->hasMany(WorkerZone::class);
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

    public function casts(): array
    {
        return [
            'average_rating' => 'decimal:2',
            'acceptance_rate' => 'decimal:2',
            'cancellation_rate' => 'decimal:2',
            'home_latitude' => 'decimal:8',
            'home_longitude' => 'decimal:8',
            'is_active' => 'boolean',
            'is_suspended' => 'boolean',
            'is_verified' => 'boolean',
            'is_featured' => 'boolean',
            'featured_until' => 'datetime',
            'suspended_until' => 'datetime',
            'default_working_hours' => 'array',
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
}
