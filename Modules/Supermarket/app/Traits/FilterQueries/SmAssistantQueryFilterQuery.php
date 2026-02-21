<?php

declare(strict_types=1);

namespace Modules\Supermarket\Traits\FilterQueries;

use Illuminate\Database\Eloquent\Builder;
use Modules\Supermarket\Models\SmAssistantQuery;
use Mrmarchone\LaravelAutoCrud\Helpers\SearchTermEscaper;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\AllowedSort;
use Spatie\QueryBuilder\QueryBuilder;

trait SmAssistantQueryFilterQuery
{
    public static function getQuery(): QueryBuilder
    {
        return QueryBuilder::for(SmAssistantQuery::class)
            ->allowedFilters([
                AllowedFilter::exact('userId', 'user_id'),
                AllowedFilter::exact('storeId', 'store_id'),
                AllowedFilter::exact('inputMode', 'input_mode'),
                AllowedFilter::exact('matchedRecipeId', 'matched_recipe_id'),
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
            $q->whereRaw("query_text LIKE ? ESCAPE '!'", ["%{$escapedSearch}%"]);
        });
    }
}
