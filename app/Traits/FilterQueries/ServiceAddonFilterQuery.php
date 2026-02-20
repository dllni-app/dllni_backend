<?php

declare(strict_types=1);

namespace App\Traits\FilterQueries;

use App\Models\ServiceAddon;
use Illuminate\Database\Eloquent\Builder;
use Mrmarchone\LaravelAutoCrud\Helpers\SearchTermEscaper;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\AllowedSort;
use Spatie\QueryBuilder\QueryBuilder;

trait ServiceAddonFilterQuery
{
    public static function getQuery(): QueryBuilder
    {
        return QueryBuilder::for(ServiceAddon::class)
            ->allowedFilters([
                AllowedFilter::exact('isActive', 'is_active'),
                AllowedFilter::exact('pricingType', 'pricing_type'),
                AllowedFilter::scope('search'),
            ])
            ->allowedSorts([
                AllowedSort::field('name'),
                AllowedSort::field('pricingType', 'pricing_type'),
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
                ->orWhereRaw('slug LIKE ? ESCAPE \'!\'', [$likeTerm]);
        });
    }
}
