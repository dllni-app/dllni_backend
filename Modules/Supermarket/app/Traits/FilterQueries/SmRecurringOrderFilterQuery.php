<?php

declare(strict_types=1);

namespace Modules\Supermarket\Traits\FilterQueries;

use Modules\Supermarket\Models\SmRecurringOrder;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\AllowedSort;
use Spatie\QueryBuilder\QueryBuilder;

trait SmRecurringOrderFilterQuery
{
    public static function getQuery(): QueryBuilder
    {
        return QueryBuilder::for(SmRecurringOrder::class)
            ->allowedFilters([
                AllowedFilter::exact('userId', 'user_id'),
                AllowedFilter::exact('storeId', 'store_id'),
                AllowedFilter::exact('status'),
                AllowedFilter::partial('frequency'),
            ])
            ->allowedSorts([
                AllowedSort::field('nextRunAt', 'next_run_at'),
                AllowedSort::field('createdAt', 'created_at'),
            ])
            ->defaultSort('-created_at');
    }
}
