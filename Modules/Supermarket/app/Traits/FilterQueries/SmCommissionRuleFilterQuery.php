<?php

declare(strict_types=1);

namespace Modules\Supermarket\Traits\FilterQueries;

use Illuminate\Database\Eloquent\Builder;
use Modules\Supermarket\Models\SmCommissionRule;
use Mrmarchone\LaravelAutoCrud\Helpers\SearchTermEscaper;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\AllowedSort;
use Spatie\QueryBuilder\QueryBuilder;

trait SmCommissionRuleFilterQuery
{
    public static function getQuery(): QueryBuilder
    {
        return QueryBuilder::for(SmCommissionRule::class)
            ->allowedFilters([
                AllowedFilter::exact('storeId', 'store_id'),
                AllowedFilter::partial('commissionType', 'commission_type'),
                AllowedFilter::exact('isDefault', 'is_default'),
                AllowedFilter::exact('isActive', 'is_active'),
                AllowedFilter::scope('search'),
            ])
            ->allowedSorts([
                AllowedSort::field('value'),
                AllowedSort::field('startsAt', 'starts_at'),
                AllowedSort::field('endsAt', 'ends_at'),
                AllowedSort::field('createdAt', 'created_at'),
            ])
            ->defaultSort('-created_at');
    }

    public function scopeSearch($query, string $search): Builder
    {
        $escapedSearch = SearchTermEscaper::escape($search);

        return $query->where(function ($q) use ($escapedSearch) {
            $q->whereRaw("commission_type LIKE ? ESCAPE '!'", ["%{$escapedSearch}%"]);
        });
    }
}
