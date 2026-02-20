<?php

declare(strict_types=1);

namespace Modules\Resturants\Traits\FilterQueries;

use Illuminate\Database\Eloquent\Builder;
use Modules\Resturants\Models\RestaurantAssistantQuery;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\AllowedSort;
use Spatie\QueryBuilder\QueryBuilder;

trait RestaurantAssistantQueryFilterQuery
{
    public static function getQuery(): QueryBuilder
    {
        return QueryBuilder::for(RestaurantAssistantQuery::class)
            ->allowedFilters([
                AllowedFilter::exact('restaurantId', 'restaurant_id'),
                AllowedFilter::exact('userId', 'user_id'),
                AllowedFilter::exact('inputMode', 'input_mode'),
                AllowedFilter::scope('hasRecipeMatch'),
                AllowedFilter::scope('dateFrom'),
                AllowedFilter::scope('dateTo'),
            ])
            ->allowedSorts([
                AllowedSort::field('createdAt', 'created_at'),
            ])
            ->defaultSort('-created_at');
    }

    public function scopeHasRecipeMatch(Builder $query, mixed $value): Builder
    {
        if (! filter_var($value, FILTER_VALIDATE_BOOLEAN)) {
            return $query;
        }

        return $query->whereNotNull('matched_recipe_id');
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
