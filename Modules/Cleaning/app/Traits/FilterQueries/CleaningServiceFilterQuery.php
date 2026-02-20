<?php

declare(strict_types=1);

namespace Modules\Cleaning\Traits\FilterQueries;

use Illuminate\Database\Eloquent\Builder;
use Modules\Cleaning\Models\CleaningService;
use Mrmarchone\LaravelAutoCrud\Helpers\SearchTermEscaper;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\AllowedSort;
use Spatie\QueryBuilder\QueryBuilder;

trait CleaningServiceFilterQuery
{
    public static function getQuery(): QueryBuilder
    {
        return QueryBuilder::for(CleaningService::class)
            ->allowedFilters([
                AllowedFilter::exact('isActive', 'is_active'),
                AllowedFilter::exact('category'),
                AllowedFilter::scope('search'),
            ])
            ->allowedSorts([
                AllowedSort::field('name'),
                AllowedSort::field('category'),
                AllowedSort::field('createdAt', 'created_at'),
            ])
            ->defaultSort('-created_at');
    }

    public function scopeSearch(Builder $query, string $search): Builder
    {
        if (empty($search)) {
            return $query;
        }

        $likeTerm = SearchTermEscaper::escape($search);

        return $query->where(function (Builder $q) use ($likeTerm) {
            $q->whereRaw('name LIKE ? ESCAPE \'!\'', [$likeTerm])
                ->orWhereRaw('slug LIKE ? ESCAPE \'!\'', [$likeTerm])
                ->orWhereRaw('description LIKE ? ESCAPE \'!\'', [$likeTerm]);
        });
    }
}
