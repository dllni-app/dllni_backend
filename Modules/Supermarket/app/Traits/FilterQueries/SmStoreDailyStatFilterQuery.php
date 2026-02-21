<?php

declare(strict_types=1);

namespace Modules\Supermarket\Traits\FilterQueries;

use Illuminate\Database\Eloquent\Builder;
use Modules\Supermarket\Models\SmStoreDailyStat;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\AllowedSort;
use Spatie\QueryBuilder\QueryBuilder;

trait SmStoreDailyStatFilterQuery
{
    public static function getQuery(): QueryBuilder
    {
        return QueryBuilder::for(SmStoreDailyStat::class)
            ->allowedFilters([
                AllowedFilter::exact('storeId', 'store_id'),
                AllowedFilter::scope('dateFrom'),
                AllowedFilter::scope('dateTo'),
            ])
            ->allowedSorts([
                AllowedSort::field('date'),
                AllowedSort::field('createdAt', 'created_at'),
            ])
            ->defaultSort('-date');
    }

    public function scopeDateFrom($query, string $date): Builder
    {
        return $query->whereDate('date', '>=', $date);
    }

    public function scopeDateTo($query, string $date): Builder
    {
        return $query->whereDate('date', '<=', $date);
    }
}
