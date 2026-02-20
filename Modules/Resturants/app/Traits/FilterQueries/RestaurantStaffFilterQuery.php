<?php

declare(strict_types=1);

namespace Modules\Resturants\Traits\FilterQueries;

use Modules\Resturants\Models\RestaurantStaff;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\AllowedSort;
use Spatie\QueryBuilder\QueryBuilder;

trait RestaurantStaffFilterQuery
{
    public static function getQuery(): QueryBuilder
    {
        return QueryBuilder::for(RestaurantStaff::class)
            ->allowedFilters([
                AllowedFilter::exact('restaurantId', 'restaurant_id'),
                AllowedFilter::exact('userId', 'user_id'),
                AllowedFilter::exact('restaurantRoleId', 'restaurant_role_id'),
            ])
            ->allowedSorts([
                AllowedSort::field('createdAt', 'created_at'),
            ])
            ->defaultSort('-created_at');
    }
}
