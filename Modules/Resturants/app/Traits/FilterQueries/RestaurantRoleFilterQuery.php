<?php

declare(strict_types=1);

namespace Modules\Resturants\Traits\FilterQueries;

use Modules\Resturants\Models\RestaurantRole;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\AllowedSort;
use Spatie\QueryBuilder\QueryBuilder;

trait RestaurantRoleFilterQuery
{
    public static function getQuery(): QueryBuilder
    {
        return QueryBuilder::for(RestaurantRole::class)
            ->allowedFilters([
                AllowedFilter::exact('restaurantId', 'restaurant_id'),
            ])
            ->allowedSorts([
                AllowedSort::field('name'),
                AllowedSort::field('slug'),
                AllowedSort::field('createdAt', 'created_at'),
            ])
            ->defaultSort('name');
    }
}
