<?php

declare(strict_types=1);

namespace Modules\Supermarket\Traits\FilterQueries;

use Illuminate\Database\Eloquent\Builder;
use Modules\Supermarket\Models\SmOrderItem;
use Mrmarchone\LaravelAutoCrud\Helpers\SearchTermEscaper;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\AllowedSort;
use Spatie\QueryBuilder\QueryBuilder;

trait SmOrderItemFilterQuery
{
    public static function getQuery(): QueryBuilder
    {
        return QueryBuilder::for(SmOrderItem::class)
            ->allowedFilters([
                AllowedFilter::exact('orderId', 'order_id'),
                AllowedFilter::exact('productId', 'product_id'),
                AllowedFilter::partial('productName', 'product_name'),
                AllowedFilter::scope('search'),
            ])
            ->allowedSorts([
                AllowedSort::field('quantity'),
                AllowedSort::field('totalPrice', 'total_price'),
                AllowedSort::field('createdAt', 'created_at'),
            ])
            ->defaultSort('-created_at');
    }

    public function scopeSearch($query, string $search): Builder
    {
        $escapedSearch = SearchTermEscaper::escape($search);

        return $query->where(function ($q) use ($escapedSearch) {
            $q->whereRaw("product_name LIKE ? ESCAPE '!'", ["%{$escapedSearch}%"]);
        });
    }
}
