<?php

declare(strict_types=1);

namespace Modules\Resturants\Traits\FilterQueries;

use Illuminate\Database\Eloquent\Builder;
use Modules\Resturants\Models\RestaurantReputationLog;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\AllowedSort;
use Spatie\QueryBuilder\QueryBuilder;

trait RestaurantReputationLogFilterQuery
{
    public static function getQuery(): QueryBuilder
    {
        return QueryBuilder::for(RestaurantReputationLog::class)
            ->allowedFilters([
                AllowedFilter::exact('restaurantId', 'restaurant_id'),
                AllowedFilter::scope('dateFrom'),
                AllowedFilter::scope('dateTo'),
            ])
            ->allowedSorts([
                AllowedSort::field('scoreDelta', 'score_delta'),
                AllowedSort::field('createdAt', 'created_at'),
            ])
            ->defaultSort('-created_at');
    }

    public function scopeDateFrom(Builder $query, string $date): Builder
    {
        return $query->whereDate('created_at', '>=', $date);
    }

    public function scopeDateTo(Builder $query, string $date): Builder
    {
        return $query->whereDate('created_at', '<=', $date);
    }
}
