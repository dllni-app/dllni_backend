<?php

declare(strict_types=1);

namespace Modules\Resturants\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class RestaurantRecurringOrderItem extends Model
{
    protected $fillable = [
        'recurring_order_id',
        'product_id',
        'quantity',
        'special_instructions',
        'sort_order',
    ];

    public function recurringOrder(): BelongsTo
    {
        return $this->belongsTo(RestaurantRecurringOrder::class, 'recurring_order_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'sort_order' => 'integer',
        ];
    }
}
