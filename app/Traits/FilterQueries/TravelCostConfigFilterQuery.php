<?php

declare(strict_types=1);

namespace App\Traits\FilterQueries;

use App\Models\TravelCostConfig;
use Illuminate\Database\Eloquent\Builder;
use Mrmarchone\LaravelAutoCrud\Helpers\SearchTermEscaper;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\AllowedSort;
use Spatie\QueryBuilder\QueryBuilder;

trait TravelCostConfigFilterQuery
{
    public static function getQuery(): QueryBuilder
    {
        return QueryBuilder::for(TravelCostConfig::class)
            ->allowedFilters([
                AllowedFilter::exact('isActive', 'is_active'),
                AllowedFilter::scope('search'),
            ])
            ->allowedSorts([
                AllowedSort::field('name'),
                AllowedSort::field('maxKm', 'max_km'),
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

        return $query->whereRaw('name LIKE ? ESCAPE \'!\'', [$likeTerm]);
    }
}
