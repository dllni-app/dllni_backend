<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EmergencyType;
use App\Enums\SOSStatus;
use App\Traits\FilterQueries\SosAlertFilterQuery;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Modules\Resturants\Models\Order;

final class SosAlert extends Model
{
    use SosAlertFilterQuery;

    protected $fillable = [
        'user_id',
        'order_id',
        'booking_id',
        'booking_type',
        'emergency_type',
        'message',
        'source',
        'status',
        'latitude',
        'longitude',
        'triggered_at',
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

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
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
            'emergency_type' => EmergencyType::class,
            'status' => SOSStatus::class,
            'message' => 'string',
            'source' => 'string',
            'latitude' => 'decimal:8',
            'longitude' => 'decimal:8',
            'triggered_at' => 'datetime',
            'acknowledged_at' => 'datetime',
            'resolved_at' => 'datetime',
            'acknowledged_by' => 'integer',
            'resolved_by' => 'integer',
            'resolution_note' => 'string',
        ];
    }
}
