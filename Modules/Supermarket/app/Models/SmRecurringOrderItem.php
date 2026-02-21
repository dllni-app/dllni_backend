<?php

declare(strict_types=1);

namespace Modules\Supermarket\Models;

use App\Models\MasterProduct;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Supermarket\Traits\FilterQueries\SmRecurringOrderItemFilterQuery;

final class SmRecurringOrderItem extends Model
{
    use SmRecurringOrderItemFilterQuery;

    protected $table = 'sm_recurring_order_items';

    protected $fillable = [
        'recurring_order_id',
        'master_product_id',
        'quantity',
        'unit',
        'sort_order',
    ];

    public function recurringOrder(): BelongsTo
    {
        return $this->belongsTo(SmRecurringOrder::class, 'recurring_order_id');
    }

    public function masterProduct(): BelongsTo
    {
        return $this->belongsTo(MasterProduct::class, 'master_product_id');
    }

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:2',
        ];
    }
}
