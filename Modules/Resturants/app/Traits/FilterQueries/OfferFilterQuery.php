<?php

declare(strict_types=1);

namespace Modules\Resturants\Traits\FilterQueries;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Modules\Resturants\Models\Offer;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\AllowedSort;
use Spatie\QueryBuilder\QueryBuilder;

trait OfferFilterQuery
{
    public static function getQuery(): QueryBuilder
    {
        return QueryBuilder::for(Offer::class)
            ->allowedFilters([
                AllowedFilter::exact('restaurantId', 'restaurant_id'),
                AllowedFilter::exact('isActive', 'is_active'),
                AllowedFilter::scope('startsAtFrom'),
                AllowedFilter::scope('endsAtTo'),
                AllowedFilter::scope('scheduled'),
            ])
            ->allowedSorts([
                AllowedSort::field('name'),
                AllowedSort::field('startsAt', 'starts_at'),
                AllowedSort::field('endsAt', 'ends_at'),
                AllowedSort::field('createdAt', 'created_at'),
            ])
            ->defaultSort('-created_at');
    }

    public function scopeStartsAtFrom(Builder $query, string $date): Builder
    {
        return $query->where('starts_at', '>=', $date);
    }

    public function scopeEndsAtTo(Builder $query, string $date): Builder
    {
        return $query->where('ends_at', '<=', $date);
    }

    public function scopeScheduled(Builder $query, mixed $value): Builder
    {
        if (! filter_var($value, FILTER_VALIDATE_BOOLEAN)) {
            return $query;
        }

        return $query->where('starts_at', '>', Carbon::now());
    }
}
