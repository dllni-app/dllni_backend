<?php

declare(strict_types=1);

namespace Modules\Resturants\Traits\FilterQueries;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Modules\Resturants\Models\Product;
use Mrmarchone\LaravelAutoCrud\Helpers\SearchTermEscaper;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\AllowedSort;
use Spatie\QueryBuilder\QueryBuilder;

trait ProductFilterQuery
{
    public static function getQuery(): QueryBuilder
    {
        return QueryBuilder::for(Product::class)
            ->allowedFilters([
                AllowedFilter::exact('restaurantId', 'restaurant_id'),
                AllowedFilter::exact('categoryId', 'category_id'),
                AllowedFilter::exact('isAvailable', 'is_available'),
                AllowedFilter::scope('lowStock'),
                AllowedFilter::exact('isFeatured', 'is_featured'),
                AllowedFilter::scope('search'),
                AllowedFilter::scope('minPrice'),
                AllowedFilter::scope('maxPrice'),
                AllowedFilter::scope('hasDiscount'),
                AllowedFilter::scope('createdAfter'),
                AllowedFilter::scope('createdBefore'),
            ])
            ->allowedSorts([
                AllowedSort::field('name'),
                AllowedSort::field('price'),
                AllowedSort::field('createdAt', 'created_at'),
            ])
            ->defaultSort('-created_at');
    }

    public function scopeCreatedAfter(Builder $query, mixed $date): Builder
    {
        $dateTime = is_string($date) ? Carbon::parse($date)->startOfDay() : $date;

        return $query->where('created_at', '>=', $dateTime);
    }

    public function scopeCreatedBefore(Builder $query, mixed $date): Builder
    {
        $dateTime = is_string($date) ? Carbon::parse($date)->endOfDay() : $date;

        return $query->where('created_at', '<=', $dateTime);
    }

    public function scopeSearch(Builder $query, string $search): Builder
    {
        if (empty($search)) {
            return $query;
        }

        $likeTerm = SearchTermEscaper::escape($search);

        return $query->whereRaw("name LIKE ? ESCAPE '!'", [$likeTerm]);
    }

    public function scopeMinPrice(Builder $query, mixed $value): Builder
    {
        if (! is_numeric($value)) {
            return $query;
        }

        return $query->where('price', '>=', (float) $value);
    }

    public function scopeMaxPrice(Builder $query, mixed $value): Builder
    {
        if (! is_numeric($value)) {
            return $query;
        }

        return $query->where('price', '<=', (float) $value);
    }

    public function scopeHasDiscount(Builder $query, mixed $value): Builder
    {
        if (! filter_var($value, FILTER_VALIDATE_BOOLEAN)) {
            return $query;
        }

        return $query->whereNotNull('discounted_price')
            ->whereColumn('discounted_price', '<', 'price');
    }
}
