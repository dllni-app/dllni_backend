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
        'order_id',
        'product_id',
        'quantity',
        'unit_price',
        'total_price',
        'product_name',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(SmOrder::class, 'order_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(SmProduct::class, 'product_id');
    }

    protected function casts(): array
    {
        return [
            'unit_price' => 'decimal:2',
            'total_price' => 'decimal:2',
        ];
    }
}
