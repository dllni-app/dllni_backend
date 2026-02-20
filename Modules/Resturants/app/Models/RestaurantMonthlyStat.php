<?php

declare(strict_types=1);

namespace Modules\Resturants\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class RestaurantMonthlyStat extends Model
{
    protected $fillable = [
        'restaurant_id',
        'stat_year',
        'stat_month',
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
            'stat_year' => 'integer',
            'stat_month' => 'integer',
            'orders_count' => 'integer',
            'revenue' => 'decimal:2',
            'average_order_value' => 'decimal:2',
        ];
    }
}
