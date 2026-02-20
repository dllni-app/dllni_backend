<?php

declare(strict_types=1);

namespace Modules\Supermarket\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class SmCartItem extends Model
{
    protected $table = 'sm_cart_items';

    protected $fillable = [
        'cart_id',
        'product_id',
        'quantity',
        'unit_price',
    ];

    public function cart(): BelongsTo
    {
        return $this->belongsTo(SmCart::class, 'cart_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(SmProduct::class, 'product_id');
    }

    protected function casts(): array
    {
        return [
            'unit_price' => 'decimal:2',
        ];
    }
}
