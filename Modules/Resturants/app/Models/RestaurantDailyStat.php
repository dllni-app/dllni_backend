<?php

declare(strict_types=1);

namespace Modules\Resturants\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class RestaurantDailyStat extends Model
{
    protected $fillable = [
        'restaurant_id',
        'stat_date',
        'orders_count',
        'revenue',
        'average_order_value',
    ];

    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }

    protected function casts(): array
    {
        return [
            'stat_date' => 'date',
            'orders_count' => 'integer',
            'revenue' => 'decimal:2',
            'average_order_value' => 'decimal:2',
        ];
    }
}
