<?php

declare(strict_types=1);

namespace Modules\Resturants\Traits\FilterQueries;

use Illuminate\Database\Eloquent\Builder;
use Modules\Resturants\Models\RestaurantOrderDispute;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\AllowedSort;
use Spatie\QueryBuilder\QueryBuilder;

trait RestaurantOrderDisputeFilterQuery
{
    public static function getQuery(): QueryBuilder
    {
        return QueryBuilder::for(RestaurantOrderDispute::class)
            ->allowedFilters([
                AllowedFilter::exact('status'),
                AllowedFilter::scope('restaurantId'),
                AllowedFilter::scope('dateFrom'),
                AllowedFilter::scope('dateTo'),
            ])
            ->allowedSorts([
                AllowedSort::field('ticketNumber', 'ticket_number'),
                AllowedSort::field('status'),
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
