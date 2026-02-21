<?php

declare(strict_types=1);

namespace Modules\Supermarket\Traits\FilterQueries;

use Illuminate\Database\Eloquent\Builder;
use Modules\Supermarket\Models\SmInventoryLog;
use Mrmarchone\LaravelAutoCrud\Helpers\SearchTermEscaper;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\AllowedSort;
use Spatie\QueryBuilder\QueryBuilder;

trait SmInventoryLogFilterQuery
{
    public static function getQuery(): QueryBuilder
    {
        return QueryBuilder::for(SmInventoryLog::class)
            ->allowedFilters([
                AllowedFilter::exact('productId', 'product_id'),
                AllowedFilter::exact('type'),
                AllowedFilter::exact('userId', 'user_id'),
                AllowedFilter::exact('referenceType', 'reference_type'),
                AllowedFilter::exact('referenceId', 'reference_id'),
                AllowedFilter::scope('search'),
            ])
            ->allowedSorts([
                AllowedSort::field('quantityChange', 'quantity_change'),
                AllowedSort::field('createdAt', 'created_at'),
            ])
            ->defaultSort('-created_at');
    }

    public function scopeSearch($query, string $search): Builder
    {
        $escapedSearch = SearchTermEscaper::escape($search);

        return $query->where(function ($q) use ($escapedSearch) {
            $q->whereRaw("notes LIKE ? ESCAPE '!'", ["%{$escapedSearch}%"]);
        });
    }
}
