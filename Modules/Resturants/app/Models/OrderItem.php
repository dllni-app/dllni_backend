<?php

declare(strict_types=1);

namespace Modules\Resturants\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class OrderItem extends Model
{
    protected $fillable = [
        'order_id',
        'product_id',
        'substitute_product_id',
        'quantity',
        'unit_price',
        'total_price',
        'special_instructions',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function substituteProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'substitute_product_id');
    }

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_price' => 'decimal:2',
            'total_price' => 'decimal:2',
        ];
    }
}
