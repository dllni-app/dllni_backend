<?php

declare(strict_types=1);

namespace Modules\Supermarket\Models;

use App\Models\CancellationPolicy;
use App\Models\User;
use Database\Factories\SmOrderFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Modules\Delivery\Models\DeliveryOrder;
use Modules\Supermarket\Enums\SmOrderStatus;
use Modules\Supermarket\Enums\SmPickupMode;
use Modules\Supermarket\Traits\FilterQueries\SmOrderFilterQuery;

final class SmOrder extends Model
{
    use HasFactory;
    use SmOrderFilterQuery;

    protected $table = 'sm_orders';

    protected $fillable = [
        'customer_id',
        'store_id',
        'coupon_id',
        'cancellation_policy_id',
        'order_number',
        'status',
        'pickup_mode',
        'pickup_scheduled_for',
        'ready_for_pickup_at',
        'picked_up_at',
        'customer_pickup_confirmed_at',
        'subtotal',
        'discount_amount',
        'service_fee',
        'total_amount',
        'cancellation_fee_amount',
        'cancellation_policy_snapshot',
        'special_instructions',
        'cancelled_at',
        'cancellation_reason',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(SmStore::class, 'store_id');
    }

    public function deliveryOrder(): MorphOne
    {
        return $this->morphOne(DeliveryOrder::class, 'source', 'source_type', 'source_id');
    }

    public function coupon(): BelongsTo
    {
        return $this->belongsTo(SmCoupon::class, 'coupon_id');
    }

    public function cancellationPolicy(): BelongsTo
    {
        return $this->belongsTo(CancellationPolicy::class, 'cancellation_policy_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(SmOrderItem::class, 'order_id');
    }

    public function statusLogs(): HasMany
    {
        return $this->hasMany(SmOrderStatusLog::class, 'order_id');
    }

    public function disputes(): HasMany
    {
        return $this->hasMany(SmOrderDispute::class, 'order_id');
    }

    protected static function newFactory(): Factory
    {
        return SmOrderFactory::new();
    }

    protected function casts(): array
    {
        return [
            'status' => SmOrderStatus::class,
            'pickup_mode' => SmPickupMode::class,
            'pickup_scheduled_for' => 'datetime',
            'ready_for_pickup_at' => 'datetime',
            'picked_up_at' => 'datetime',
            'customer_pickup_confirmed_at' => 'datetime',
            'subtotal' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'service_fee' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'cancellation_fee_amount' => 'decimal:2',
            'cancellation_policy_snapshot' => 'array',
            'cancelled_at' => 'datetime',
        ];
    }
}
