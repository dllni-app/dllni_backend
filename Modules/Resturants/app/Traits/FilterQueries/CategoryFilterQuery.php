<?php

declare(strict_types=1);

namespace Modules\Resturants\Traits\FilterQueries;

use Illuminate\Database\Eloquent\Builder;
use Modules\Resturants\Models\Category;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\AllowedSort;
use Spatie\QueryBuilder\QueryBuilder;

trait CategoryFilterQuery
{
    public static function getQuery(): QueryBuilder
    {
        return QueryBuilder::for(Category::class)
            ->allowedFilters([
                AllowedFilter::exact('restaurantId', 'restaurant_id'),
                AllowedFilter::scope('search'),
            ])
            ->allowedSorts([
                AllowedSort::field('name'),
                AllowedSort::field('sortOrder', 'sort_order'),
                AllowedSort::field('createdAt', 'created_at'),
            ])
            ->defaultSort('sort_order');
    }

    public function scopeSearch(Builder $query, string $search): Builder
    {
        $term = '%'.addcslashes($search, '%_\\').'%';

        return $query->where(function (Builder $q) use ($term) {
            $q->where('name', 'like', $term)
                ->orWhere('slug', 'like', $term);
        });
    }
}
