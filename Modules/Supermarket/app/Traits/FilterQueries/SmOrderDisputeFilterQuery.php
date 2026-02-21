<?php

declare(strict_types=1);

namespace Modules\Supermarket\Traits\FilterQueries;

use Illuminate\Database\Eloquent\Builder;
use Modules\Supermarket\Models\SmOrderDispute;
use Mrmarchone\LaravelAutoCrud\Helpers\SearchTermEscaper;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\AllowedSort;
use Spatie\QueryBuilder\QueryBuilder;

trait SmOrderDisputeFilterQuery
{
    public static function getQuery(): QueryBuilder
    {
        return QueryBuilder::for(SmOrderDispute::class)
            ->allowedFilters([
                AllowedFilter::exact('orderId', 'order_id'),
                AllowedFilter::exact('openedByUserId', 'opened_by_user_id'),
                AllowedFilter::exact('resolvedByUserId', 'resolved_by_user_id'),
                AllowedFilter::exact('status'),
                AllowedFilter::partial('ticketNumber', 'ticket_number'),
                AllowedFilter::scope('search'),
            ])
            ->allowedSorts([
                AllowedSort::field('ticketNumber', 'ticket_number'),
                AllowedSort::field('status'),
                AllowedSort::field('createdAt', 'created_at'),
            ])
            ->defaultSort('-created_at');
    }

    public function scopeSearch($query, string $search): Builder
    {
        $escapedSearch = SearchTermEscaper::escape($search);

        return $query->where(function ($q) use ($escapedSearch) {
            $q->whereRaw("ticket_number LIKE ? ESCAPE '!'", ["%{$escapedSearch}%"])
                ->orWhereRaw("reason LIKE ? ESCAPE '!'", ["%{$escapedSearch}%"])
                ->orWhereRaw("description LIKE ? ESCAPE '!'", ["%{$escapedSearch}%"])
                ->orWhereRaw("resolution_notes LIKE ? ESCAPE '!'", ["%{$escapedSearch}%"]);
        });
    }
}
