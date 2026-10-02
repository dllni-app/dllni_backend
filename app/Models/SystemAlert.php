<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AlertSeverity;
use App\Enums\AlertType;
use App\Enums\SystemAlertStatus;
use App\Traits\FilterQueries\SystemAlertFilterQuery;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

final class SystemAlert extends Model
{
    use SystemAlertFilterQuery;

    protected $fillable = [
        'booking_id',
        'booking_type',
        'alert_type',
        'severity',
        'status',
        'payload',
        'acknowledged_at',
        'acknowledged_by',
        'resolved_at',
        'resolved_by',
        'resolution_note',
    ];

    public function booking(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'booking_type', 'booking_id');
    }

    public function acknowledgedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acknowledged_by');
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function casts(): array
    {
        return [
            'alert_type' => AlertType::class,
            'severity' => AlertSeverity::class,
            'status' => SystemAlertStatus::class,
            'payload' => 'array',
            'acknowledged_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }
}
