<?php

declare(strict_types=1);

namespace Modules\Supermarket\Traits\FilterQueries;

use Illuminate\Database\Eloquent\Builder;
use Modules\Supermarket\Models\SmProduct;
use Mrmarchone\LaravelAutoCrud\Helpers\SearchTermEscaper;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\AllowedSort;
use Spatie\QueryBuilder\QueryBuilder;

trait SmProductFilterQuery
{
    public static function getQuery(): QueryBuilder
    {
        return QueryBuilder::for(SmProduct::class)
            ->allowedFilters([
                AllowedFilter::exact('storeId', 'store_id'),
                AllowedFilter::exact('categoryId', 'category_id'),
                AllowedFilter::partial('barcode'),
                AllowedFilter::exact('sourceType', 'source_type'),
                AllowedFilter::exact('isAvailable', 'is_available'),
                AllowedFilter::scope('lowStock'),
                AllowedFilter::scope('expiringSoon'),
                AllowedFilter::scope('search'),
            ])
            ->allowedSorts([
                AllowedSort::field('name'),
                AllowedSort::field('price'),
                AllowedSort::field('stockQuantity', 'stock_quantity'),
                AllowedSort::field('expiresAt', 'expires_at'),
                AllowedSort::field('createdAt', 'created_at'),
            ])
            ->defaultSort('-created_at');
    }

    public function scopeLowStock($query, $value): Builder
    {
        if (! filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)) {
            return $query;
        }

        return $query->whereColumn('stock_quantity', '<=', 'low_stock_threshold');
    }

    public function scopeExpiringSoon($query, $value): Builder
    {
        if (! filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)) {
            return $query;
        }

        return $query->where('expires_at', '<=', now()->addDays(7))
            ->whereNotNull('expires_at');
    }

    public function scopeSearch($query, string $search): Builder
    {
        $escapedSearch = SearchTermEscaper::escape($search);

        return $query->where(function ($q) use ($escapedSearch) {
            $q->whereRaw("name LIKE ? ESCAPE '!'", ["%{$escapedSearch}%"])
                ->orWhereRaw("barcode LIKE ? ESCAPE '!'", ["%{$escapedSearch}%"])
                ->orWhereRaw("description LIKE ? ESCAPE '!'", ["%{$escapedSearch}%"]);
        });
    }
}
