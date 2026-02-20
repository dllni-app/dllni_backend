<?php

declare(strict_types=1);

namespace Modules\Resturants\Traits\FilterQueries;

use Illuminate\Database\Eloquent\Builder;
use Modules\Resturants\Models\Review;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\AllowedSort;
use Spatie\QueryBuilder\QueryBuilder;

trait ReviewFilterQuery
{
    public static function getQuery(): QueryBuilder
    {
        return QueryBuilder::for(Review::class)
            ->allowedFilters([
                AllowedFilter::exact('restaurantId', 'restaurant_id'),
                AllowedFilter::scope('ratingMin'),
                AllowedFilter::scope('ratingMax'),
                AllowedFilter::scope('dateFrom'),
                AllowedFilter::scope('dateTo'),
            ])
            ->allowedSorts([
                AllowedSort::field('rating'),
                AllowedSort::field('createdAt', 'created_at'),
            ])
            ->defaultSort('-created_at');
    }

    public function scopeRatingMin(Builder $query, mixed $value): Builder
    {
        if (! is_numeric($value)) {
            return $query;
        }

        return $query->where('rating', '>=', (int) $value);
    }

    public function scopeRatingMax(Builder $query, mixed $value): Builder
    {
        if (! is_numeric($value)) {
            return $query;
        }

        return $query->where('rating', '<=', (int) $value);
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
