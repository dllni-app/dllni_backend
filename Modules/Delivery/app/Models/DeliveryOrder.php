<?php

declare(strict_types=1);

namespace Modules\Delivery\Models;

use Database\Factories\DeliveryOrderFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

final class DeliveryOrder extends Model
{
    use HasFactory;

    protected $table = 'delivery_orders';

    protected $fillable = ['company_id', 'driver_id', 'order_number', 'customer_name', 'customer_phone', 'customer_notes', 'pickup_address', 'pickup_latitude', 'pickup_longitude', 'dropoff_address', 'dropoff_latitude', 'dropoff_longitude', 'distance_km', 'delivery_fee', 'currency', 'status', 'accepted_at', 'started_at', 'picked_up_at', 'delivered_at', 'completed_at', 'stopped_at', 'cancelled_at', 'stop_reason', 'cancel_reason', 'created_by_user_id'];

    public function company(): BelongsTo
    {
        return $this->belongsTo(DeliveryCompany::class);
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(DeliveryDriver::class)->withDefault();
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'created_by_user_id');
    }

    public function assignmentAttempts(): HasMany
    {
        return $this->hasMany(DeliveryAssignmentAttempt::class, 'order_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(DeliveryOrderEvent::class, 'order_id');
    }

    public function disputes(): MorphMany
    {
        return $this->morphMany(\App\Models\Dispute::class, 'booking');
    }

    public function statusLogs(): MorphMany
    {
        return $this->morphMany(\App\Models\BookingStatusLog::class, 'booking', 'booking_type', 'booking_id');
    }

    protected static function newFactory(): DeliveryOrderFactory
    {
        return DeliveryOrderFactory::new();
    }

    protected function casts(): array
    {
        return ['pickup_latitude' => 'decimal:8', 'pickup_longitude' => 'decimal:8', 'dropoff_latitude' => 'decimal:8', 'dropoff_longitude' => 'decimal:8', 'distance_km' => 'decimal:2', 'delivery_fee' => 'decimal:2', 'accepted_at' => 'datetime', 'started_at' => 'datetime', 'picked_up_at' => 'datetime', 'delivered_at' => 'datetime', 'completed_at' => 'datetime', 'stopped_at' => 'datetime', 'cancelled_at' => 'datetime'];
    }
}
