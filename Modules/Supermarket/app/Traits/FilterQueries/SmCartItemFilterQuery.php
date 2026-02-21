<?php

declare(strict_types=1);

namespace Modules\Supermarket\Traits\FilterQueries;

use Modules\Supermarket\Models\SmCartItem;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\AllowedSort;
use Spatie\QueryBuilder\QueryBuilder;

trait SmCartItemFilterQuery
{
    public static function getQuery(): QueryBuilder
    {
        return QueryBuilder::for(SmCartItem::class)
            ->allowedFilters([
                AllowedFilter::exact('cartId', 'cart_id'),
                AllowedFilter::exact('productId', 'product_id'),
            ])
            ->allowedSorts([
                AllowedSort::field('quantity'),
                AllowedSort::field('createdAt', 'created_at'),
            ])
            ->defaultSort('-created_at');
    }
}
