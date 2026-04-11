<?php

declare(strict_types=1);

namespace Modules\Resturants\Models;

use App\Models\CancellationPolicy;
use App\Models\User;
use Database\Factories\OrderFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Modules\Resturants\Enums\OrderStatus;
use Modules\Resturants\Enums\OrderType;
use Modules\Resturants\Enums\RestaurantPickupMode;
use Modules\Resturants\Traits\FilterQueries\OrderFilterQuery;

final class Order extends Model
{
    use HasFactory;
    use OrderFilterQuery;

    protected $fillable = [
        'user_id',
        'restaurant_id',
        'promo_code_id',
        'assigned_staff_id',
        'cancellation_policy_id',
        'order_number',
        'status',
        'order_type',
        'pickup_mode',
        'pickup_scheduled_for',
        'ready_for_pickup_at',
        'picked_up_at',
        'customer_pickup_confirmed_at',
        'subtotal',
        'discount_amount',
        'tax_amount',
        'service_fee',
        'total_amount',
        'cancellation_fee_amount',
        'cancellation_policy_snapshot',
        'special_instructions',
        'accepted_at',
        'estimated_preparation_minutes',
        'kitchen_notes',
        'preparing_at',
        'completed_at',
        'cancelled_at',
        'cancellation_reason',
        'cancellation_reason_code',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }

    public function promoCode(): BelongsTo
    {
        return $this->belongsTo(PromoCode::class);
    }

    public function assignedStaff(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_staff_id');
    }

    public function cancellationPolicy(): BelongsTo
    {
        return $this->belongsTo(CancellationPolicy::class);
    }

    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function orderStatusLogs(): HasMany
    {
        return $this->hasMany(OrderStatusLog::class)->orderBy('created_at')->orderBy('id');
    }

    public function disputes(): HasMany
    {
        return $this->hasMany(RestaurantOrderDispute::class, 'order_id');
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }

    public function customerReviews(): HasMany
    {
        return $this->hasMany(RestaurantCustomerReview::class);
    }

    public function systemAlerts(): MorphMany
    {
        return $this->morphMany(\App\Models\SystemAlert::class, 'booking', 'booking_type', 'booking_id');
    }

    protected static function newFactory(): OrderFactory
    {
        return OrderFactory::new();
    }

    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'order_type' => OrderType::class,
            'pickup_mode' => RestaurantPickupMode::class,
            'pickup_scheduled_for' => 'datetime',
            'ready_for_pickup_at' => 'datetime',
            'picked_up_at' => 'datetime',
            'customer_pickup_confirmed_at' => 'datetime',
            'subtotal' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'service_fee' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'cancellation_fee_amount' => 'decimal:2',
            'cancellation_policy_snapshot' => 'array',
            'accepted_at' => 'datetime',
            'preparing_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }
}
