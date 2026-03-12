<?php

declare(strict_types=1);

namespace Modules\Resturants\Traits\FilterQueries;

use Illuminate\Database\Eloquent\Builder;
use Modules\Resturants\Models\InventoryItem;
use Mrmarchone\LaravelAutoCrud\Helpers\SearchTermEscaper;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\AllowedSort;
use Spatie\QueryBuilder\QueryBuilder;

trait InventoryItemFilterQuery
{
    public static function getQuery(): QueryBuilder
    {
        return QueryBuilder::for(InventoryItem::class)
            ->allowedFilters([
                AllowedFilter::exact('restaurantId', 'restaurant_id'),
                AllowedFilter::scope('search'),
                AllowedFilter::scope('status'),
                AllowedFilter::scope('lowStock'),
            ])
            ->allowedSorts([
                AllowedSort::field('name'),
                AllowedSort::field('quantity'),
                AllowedSort::field('unitCost', 'unit_cost'),
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

    public function scopeStatus(Builder $query, string $status): Builder
    {
        return match ($status) {
            'low' => $query->lowStock(true),
            'normal' => $query->where(function (Builder $inner): void {
                $inner->whereColumn('quantity', '>', 'minimum_limit')
                    ->orWhereNull('minimum_limit');
            }),
            default => $query,
        };
    }
}
