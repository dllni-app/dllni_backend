<?php

declare(strict_types=1);

namespace Modules\Supermarket\Traits\FilterQueries;

use Modules\Supermarket\Models\SmStoreHours;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\AllowedSort;
use Spatie\QueryBuilder\QueryBuilder;

trait SmStoreHoursFilterQuery
{
    public static function getQuery(): QueryBuilder
    {
        return QueryBuilder::for(SmStoreHours::class)
            ->allowedFilters([
                AllowedFilter::exact('storeId', 'store_id'),
                AllowedFilter::exact('dayOfWeek', 'day_of_week'),
                AllowedFilter::exact('isClosed', 'is_closed'),
            ])
            ->allowedSorts([
                AllowedSort::field('dayOfWeek', 'day_of_week'),
                AllowedSort::field('createdAt', 'created_at'),
            ])
            ->defaultSort('-created_at');
    }
}
