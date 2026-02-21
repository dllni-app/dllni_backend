<?php

declare(strict_types=1);

namespace Modules\Supermarket\Traits\FilterQueries;

use Modules\Supermarket\Models\SmOfferProduct;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\AllowedSort;
use Spatie\QueryBuilder\QueryBuilder;

trait SmOfferProductFilterQuery
{
    public static function getQuery(): QueryBuilder
    {
        return QueryBuilder::for(SmOfferProduct::class)
            ->allowedFilters([
                AllowedFilter::exact('offerId', 'offer_id'),
                AllowedFilter::exact('productId', 'product_id'),
            ])
            ->allowedSorts([
                AllowedSort::field('offerPrice', 'offer_price'),
                AllowedSort::field('maxQuantity', 'max_quantity'),
                AllowedSort::field('createdAt', 'created_at'),
            ])
            ->defaultSort('-created_at');
    }
}
