<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EmergencyType;
use App\Enums\SOSStatus;
use App\Traits\FilterQueries\SosAlertFilterQuery;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

final class SosAlert extends Model
{
    use SosAlertFilterQuery;

    protected $fillable = [
        'booking_id',
        'booking_type',
        'emergency_type',
        'status',
        'latitude',
        'longitude',
        'triggered_at',
        'resolved_at',
    ];

    public function booking(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'booking_type', 'booking_id');
    }

    protected function casts(): array
    {
        return [
            'emergency_type' => EmergencyType::class,
            'status' => SOSStatus::class,
            'latitude' => 'decimal:8',
            'longitude' => 'decimal:8',
            'triggered_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }
}
