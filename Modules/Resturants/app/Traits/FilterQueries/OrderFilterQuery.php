<?php

declare(strict_types=1);

namespace Modules\Resturants\Traits\FilterQueries;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Modules\Resturants\Models\Order;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\AllowedSort;
use Spatie\QueryBuilder\QueryBuilder;

trait OrderFilterQuery
{
    public static function getQuery(): QueryBuilder
    {
        return QueryBuilder::for(Order::class)
            ->allowedFilters([
                AllowedFilter::exact('status'),
                AllowedFilter::exact('restaurantId', 'restaurant_id'),
                AllowedFilter::exact('orderType', 'order_type'),
                AllowedFilter::exact('pickupMode', 'pickup_mode'),
                AllowedFilter::scope('dateFrom'),
                AllowedFilter::scope('dateTo'),
                AllowedFilter::scope('createdToday'),
                AllowedFilter::scope('hasDispute'),
            ])
            ->allowedSorts([
                AllowedSort::field('orderNumber', 'order_number'),
                AllowedSort::field('status'),
                AllowedSort::field('totalAmount', 'total_amount'),
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

    public function scopeCreatedToday(Builder $query, mixed $value): Builder
    {
        if (! filter_var($value, FILTER_VALIDATE_BOOLEAN)) {
            return $query;
        }

        return $query->whereDate('created_at', Carbon::today());
    }

    public function scopeHasDispute(Builder $query, mixed $value): Builder
    {
        if (! filter_var($value, FILTER_VALIDATE_BOOLEAN)) {
            return $query;
        }

        return $query->whereHas('disputes');
    }
}
