<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\FilterQueries\WorkerFilterQuery;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Worker extends Model
{
    use HasFactory;
    use WorkerFilterQuery;

    protected $fillable = [
        'user_id',
        'first_name',
        'bio',
        'average_rating',
        'total_completed_jobs',
        'trust_score',
        'acceptance_rate',
        'cancellation_rate',
        'open_disputes_count',
        'is_active',
        'is_suspended',
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
            'suspended_until' => 'datetime',
            'default_working_hours' => 'array',
        ];
    }
}
