<?php

declare(strict_types=1);

namespace Modules\Resturants\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Resturants\Enums\RestaurantGroupOrderStatus;
use Modules\Resturants\Models\RestaurantGroupOrderItem;
use Modules\Resturants\Models\RestaurantGroupOrderParticipant;

final class RestaurantGroupOrder extends Model
{
    protected $fillable = [
        'user_id',
        'restaurant_id',
        'name',
        'share_token',
        'delivery_fee_strategy',
        'status',
        'ends_at',
        'placed_order_id',
        'placed_at',
        'cancelled_at',
    ];

    public function organizer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }

    public function placedOrder(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'placed_order_id');
    }

    public function participants(): HasMany
    {
        return $this->hasMany(RestaurantGroupOrderParticipant::class, 'group_order_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(RestaurantGroupOrderItem::class, 'group_order_id');
    }

    protected function casts(): array
    {
        return [
            'status' => RestaurantGroupOrderStatus::class,
            'ends_at' => 'datetime',
            'placed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }
}
