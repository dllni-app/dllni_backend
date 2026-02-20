<?php

declare(strict_types=1);

namespace Modules\Resturants\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Resturants\Enums\RecurringOrderStatus;
use Modules\Resturants\Traits\FilterQueries\RestaurantRecurringOrderFilterQuery;

final class RestaurantRecurringOrder extends Model
{
    use RestaurantRecurringOrderFilterQuery;

    protected $fillable = [
        'user_id',
        'restaurant_id',
        'status',
        'frequency',
        'next_run_at',
        'last_run_at',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(RestaurantRecurringOrderItem::class, 'recurring_order_id');
    }

    protected function casts(): array
    {
        return [
            'status' => RecurringOrderStatus::class,
            'next_run_at' => 'datetime',
            'last_run_at' => 'datetime',
        ];
    }
}
