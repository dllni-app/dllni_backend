<?php

declare(strict_types=1);

namespace Modules\Supermarket\Traits\FilterQueries;

use Illuminate\Database\Eloquent\Builder;
use Modules\Supermarket\Models\SmCategory;
use Mrmarchone\LaravelAutoCrud\Helpers\SearchTermEscaper;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\AllowedSort;
use Spatie\QueryBuilder\QueryBuilder;

trait SmCategoryFilterQuery
{
    public static function getQuery(): QueryBuilder
    {
        return QueryBuilder::for(SmCategory::class)
            ->allowedFilters([
                AllowedFilter::exact('storeId', 'store_id'),
                AllowedFilter::partial('name'),
                AllowedFilter::partial('slug'),
                AllowedFilter::exact('isActive', 'is_active'),
                AllowedFilter::scope('search'),
            ])
            ->allowedSorts([
                AllowedSort::field('name'),
                AllowedSort::field('slug'),
                AllowedSort::field('sortOrder', 'sort_order'),
                AllowedSort::field('createdAt', 'created_at'),
            ])
            ->defaultSort('sort_order', 'created_at');
    }

    public function scopeSearch($query, string $search): Builder
    {
        $escapedSearch = SearchTermEscaper::escape($search);

        return $query->where(function ($q) use ($escapedSearch) {
            $q->whereRaw("name LIKE ? ESCAPE '!'", ["%{$escapedSearch}%"])
                ->orWhereRaw("slug LIKE ? ESCAPE '!'", ["%{$escapedSearch}%"])
                ->orWhereRaw("description LIKE ? ESCAPE '!'", ["%{$escapedSearch}%"]);
        });
    }
}
