<?php

declare(strict_types=1);

namespace Modules\Supermarket\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Supermarket\Traits\FilterQueries\SmOrderItemFilterQuery;

final class SmOrderItem extends Model
{
    use SmOrderItemFilterQuery;

    protected $table = 'sm_order_items';

    protected $fillable = [
        'original_unit_price', 'original_total_price', 'promotion_discount_amount', 'reward_quantity', 'promotion_snapshot',
        'order_id',
        'product_id',
        'quantity',
        'unit_price',
        'total_price',
        'product_name',
        'modifier_snapshot',
        'substitute_product_id',
        'note',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(SmOrder::class, 'order_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(SmProduct::class, 'product_id');
    }

    public function substituteProduct(): BelongsTo
    {
        return $this->belongsTo(SmProduct::class, 'substitute_product_id');
    }

    protected function casts(): array
    {
        return [
            'original_unit_price' => 'decimal:2', 'original_total_price' => 'decimal:2', 'promotion_discount_amount' => 'decimal:2', 'reward_quantity' => 'integer', 'promotion_snapshot' => 'array',
            'unit_price' => 'decimal:2',
            'total_price' => 'decimal:2',
            'modifier_snapshot' => 'array',
            'substitute_product_id' => 'integer',
        ];
    }
}
