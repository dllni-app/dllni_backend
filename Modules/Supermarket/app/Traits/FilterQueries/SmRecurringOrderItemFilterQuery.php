<?php

declare(strict_types=1);

namespace Modules\Supermarket\Traits\FilterQueries;

use Modules\Supermarket\Models\SmRecurringOrderItem;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\AllowedSort;
use Spatie\QueryBuilder\QueryBuilder;

trait SmRecurringOrderItemFilterQuery
{
    public static function getQuery(): QueryBuilder
    {
        return QueryBuilder::for(SmRecurringOrderItem::class)
            ->allowedFilters([
                AllowedFilter::exact('recurringOrderId', 'recurring_order_id'),
                AllowedFilter::exact('masterProductId', 'master_product_id'),
            ])
            ->allowedSorts([
                AllowedSort::field('sortOrder', 'sort_order'),
                AllowedSort::field('createdAt', 'created_at'),
            ])
            ->defaultSort('-created_at');
    }
}
