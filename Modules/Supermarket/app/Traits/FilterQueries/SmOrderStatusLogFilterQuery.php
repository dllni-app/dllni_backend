<?php

declare(strict_types=1);

namespace Modules\Supermarket\Traits\FilterQueries;

use Illuminate\Database\Eloquent\Builder;
use Modules\Supermarket\Models\SmOrderStatusLog;
use Mrmarchone\LaravelAutoCrud\Helpers\SearchTermEscaper;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\AllowedSort;
use Spatie\QueryBuilder\QueryBuilder;

trait SmOrderStatusLogFilterQuery
{
    public static function getQuery(): QueryBuilder
    {
        return QueryBuilder::for(SmOrderStatusLog::class)
            ->allowedFilters([
                AllowedFilter::exact('orderId', 'order_id'),
                AllowedFilter::exact('changedByUserId', 'changed_by_user_id'),
                AllowedFilter::exact('fromStatus', 'from_status'),
                AllowedFilter::exact('toStatus', 'to_status'),
                AllowedFilter::scope('search'),
            ])
            ->allowedSorts([
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
