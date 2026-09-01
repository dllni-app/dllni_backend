<?php

declare(strict_types=1);

namespace Modules\Delivery\Models;

use Database\Factories\DeliveryOrderFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Modules\Delivery\Enums\DeliveryOrderStatus;
use Modules\Resturants\Models\Order as RestaurantOrder;
use Modules\Supermarket\Models\SmOrder;

final class DeliveryOrder extends Model
{
    use HasFactory;

    protected $table = 'delivery_orders';

    protected $fillable = ['company_id', 'driver_id', 'order_number', 'customer_name', 'customer_phone', 'customer_notes', 'pickup_address', 'pickup_latitude', 'pickup_longitude', 'dropoff_address', 'dropoff_latitude', 'dropoff_longitude', 'distance_km', 'delivery_fee', 'currency', 'status', 'accepted_at', 'started_at', 'picked_up_at', 'delivered_at', 'completed_at', 'stopped_at', 'cancelled_at', 'stop_reason', 'cancel_reason', 'created_by_user_id', 'source_type', 'source_id', 'merchant_status', 'merchant_accepted_at', 'estimated_preparation_minutes', 'estimated_ready_at', 'merchant_ready_at', 'dispatch_wave', 'search_radius_km', 'dispatch_phase'];

    protected static function booted(): void
    {
        static::updating(function (DeliveryOrder $order): void {
            if (! $order->isDirty('status')) {
                return;
            }

            if ($order->status !== DeliveryOrderStatus::Stopped->value || $order->driver_id === null) {
                return;
            }

            // Once a driver has accepted the order it represents an active
            // delivery lifecycle and must never be downgraded to "stopped".
            // "stopped" is reserved for dispatch/search exhaustion before a
            // driver is assigned.
            $order->status = (string) $order->getOriginal('status');
            $order->stopped_at = $order->getOriginal('stopped_at');
            $order->stop_reason = $order->getOriginal('stop_reason');
        });
    }

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

    public function source(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'source_type', 'source_id');
    }

    public function scopeOwnedByUser(Builder $query, int $userId): Builder
    {
        return $query->where(function (Builder $ownedQuery) use ($userId): void {
            $ownedQuery->where('created_by_user_id', $userId)
                ->orWhere(function (Builder $legacyQuery) use ($userId): void {
                    $legacyQuery->whereNull('created_by_user_id')
                        ->where(function (Builder $sourceQuery) use ($userId): void {
                            $sourceQuery
                                ->where(function (Builder $restaurantQuery) use ($userId): void {
                                    $restaurantQuery
                                        ->where('source_type', 'restaurant_order')
                                        ->whereIn(
                                            'source_id',
                                            RestaurantOrder::query()
                                                ->select('id')
                                                ->where('user_id', $userId),
                                        );
                                })
                                ->orWhere(function (Builder $supermarketQuery) use ($userId): void {
                                    $supermarketQuery
                                        ->where('source_type', 'supermarket_order')
                                        ->whereIn(
                                            'source_id',
                                            SmOrder::query()
                                                ->select('id')
                                                ->where('customer_id', $userId),
                                        );
                                });
                        });
                });
        });
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
        return ['pickup_latitude' => 'decimal:8', 'pickup_longitude' => 'decimal:8', 'dropoff_latitude' => 'decimal:8', 'dropoff_longitude' => 'decimal:8', 'distance_km' => 'decimal:2', 'delivery_fee' => 'decimal:2', 'accepted_at' => 'datetime', 'started_at' => 'datetime', 'picked_up_at' => 'datetime', 'delivered_at' => 'datetime', 'completed_at' => 'datetime', 'stopped_at' => 'datetime', 'cancelled_at' => 'datetime', 'merchant_accepted_at' => 'datetime', 'estimated_ready_at' => 'datetime', 'merchant_ready_at' => 'datetime', 'dispatch_wave' => 'integer', 'search_radius_km' => 'decimal:3'];
    }
}
