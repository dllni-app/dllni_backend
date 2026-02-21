<?php

declare(strict_types=1);

namespace Modules\Supermarket\Traits\FilterQueries;

use Modules\Supermarket\Models\SmCart;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\AllowedSort;
use Spatie\QueryBuilder\QueryBuilder;

trait SmCartFilterQuery
{
    public static function getQuery(): QueryBuilder
    {
        return QueryBuilder::for(SmCart::class)
            ->allowedFilters([
                AllowedFilter::exact('userId', 'user_id'),
                AllowedFilter::exact('storeId', 'store_id'),
            ])
            ->allowedSorts([
                AllowedSort::field('createdAt', 'created_at'),
            ])
            ->defaultSort('-created_at');
    }
}
