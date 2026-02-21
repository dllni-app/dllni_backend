<?php

declare(strict_types=1);

namespace Modules\Supermarket\Traits\FilterQueries;

use Illuminate\Database\Eloquent\Builder;
use Modules\Supermarket\Models\SmOrderDisputeMessage;
use Mrmarchone\LaravelAutoCrud\Helpers\SearchTermEscaper;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\AllowedSort;
use Spatie\QueryBuilder\QueryBuilder;

trait SmOrderDisputeMessageFilterQuery
{
    public static function getQuery(): QueryBuilder
    {
        return QueryBuilder::for(SmOrderDisputeMessage::class)
            ->allowedFilters([
                AllowedFilter::exact('disputeId', 'dispute_id'),
                AllowedFilter::exact('userId', 'user_id'),
                AllowedFilter::exact('isInternal', 'is_internal'),
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
            $q->whereRaw("message LIKE ? ESCAPE '!'", ["%{$escapedSearch}%"]);
        });
    }
}
