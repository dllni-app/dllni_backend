<?php

declare(strict_types=1);

namespace Modules\Supermarket\Traits\FilterQueries;

use Illuminate\Database\Eloquent\Builder;
use Modules\Supermarket\Models\SmStoreTrustLog;
use Mrmarchone\LaravelAutoCrud\Helpers\SearchTermEscaper;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\AllowedSort;
use Spatie\QueryBuilder\QueryBuilder;

trait SmStoreTrustLogFilterQuery
{
    public static function getQuery(): QueryBuilder
    {
        return QueryBuilder::for(SmStoreTrustLog::class)
            ->allowedFilters([
                AllowedFilter::exact('storeId', 'store_id'),
                AllowedFilter::exact('eventType', 'event_type'),
                AllowedFilter::exact('triggeredByUserId', 'triggered_by_user_id'),
                AllowedFilter::exact('referenceType', 'reference_type'),
                AllowedFilter::exact('referenceId', 'reference_id'),
                AllowedFilter::scope('search'),
            ])
            ->allowedSorts([
                AllowedSort::field('scoreDelta', 'score_delta'),
                AllowedSort::field('createdAt', 'created_at'),
            ])
            ->defaultSort('-created_at');
    }

    public function scopeSearch($query, string $search): Builder
    {
        $escapedSearch = SearchTermEscaper::escape($search);

        return $query->where(function ($q) use ($escapedSearch) {
            $q->whereRaw("notes LIKE ? ESCAPE '!'", ["%{$escapedSearch}%"])
                ->orWhereRaw("event_type LIKE ? ESCAPE '!'", ["%{$escapedSearch}%"]);
        });
    }
}
