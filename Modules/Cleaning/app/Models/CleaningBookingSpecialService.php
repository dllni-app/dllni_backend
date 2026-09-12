<?php

declare(strict_types=1);

namespace Modules\Cleaning\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class CleaningBookingSpecialService extends Model
{
    protected $fillable = [
        'cleaning_booking_id',
        'cleaning_booking_session_id',
        'cleaning_special_service_id',
        'assigned_worker_id',
        'service_name',
        'pricing_unit',
        'dirtiness_level',
        'quantity',
        'base_unit_price',
        'price_multiplier',
        'total_price',
        'equipment_snapshot',
        'notes',
        'execution_status',
        'unable_reason',
        'started_at',
        'completed_at',
        'financial_snapshot',
    ];

    public function booking(): BelongsTo
    {
        return $this->belongsTo(CleaningBooking::class, 'cleaning_booking_id');
    }

    public function specialService(): BelongsTo
    {
        return $this->belongsTo(CleaningSpecialService::class, 'cleaning_special_service_id');
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(CleaningBookingSession::class, 'cleaning_booking_session_id');
    }

    public function assignedWorker(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Worker::class, 'assigned_worker_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(CleaningBookingSpecialServiceItem::class);
    }

    public function sessions(): BelongsToMany
    {
        return $this->belongsToMany(
            CleaningBookingSession::class,
            'cleaning_booking_special_service_sessions',
        )->withTimestamps();
    }

    public function equipmentReservations(): HasMany
    {
        return $this->hasMany(CleaningEquipmentReservation::class);
    }

    public function casts(): array
    {
        return [
            'quantity' => 'float',
            'base_unit_price' => 'float',
            'price_multiplier' => 'float',
            'total_price' => 'float',
            'equipment_snapshot' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'financial_snapshot' => 'array',
        ];
    }
}
