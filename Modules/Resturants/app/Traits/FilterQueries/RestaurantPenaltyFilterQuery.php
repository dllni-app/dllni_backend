<?php

declare(strict_types=1);

namespace Modules\Resturants\Traits\FilterQueries;

use Modules\Resturants\Models\RestaurantPenalty;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\AllowedSort;
use Spatie\QueryBuilder\QueryBuilder;

trait RestaurantPenaltyFilterQuery
{
    public static function getQuery(): QueryBuilder
    {
        return QueryBuilder::for(RestaurantPenalty::class)
            ->allowedFilters([
                AllowedFilter::exact('restaurantId', 'restaurant_id'),
                AllowedFilter::exact('type', 'penalty_type'),
            ])
            ->allowedSorts([
                AllowedSort::field('penaltyType', 'penalty_type'),
                AllowedSort::field('createdAt', 'created_at'),
            ])
            ->defaultSort('-created_at');
    }
}
