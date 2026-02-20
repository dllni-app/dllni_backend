<?php

declare(strict_types=1);

namespace Modules\Resturants\Traits\FilterQueries;

use Modules\Resturants\Models\RestaurantDocument;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\AllowedSort;
use Spatie\QueryBuilder\QueryBuilder;

trait RestaurantDocumentFilterQuery
{
    public static function getQuery(): QueryBuilder
    {
        return QueryBuilder::for(RestaurantDocument::class)
            ->allowedFilters([
                AllowedFilter::exact('restaurantId', 'restaurant_id'),
                AllowedFilter::exact('documentType', 'document_type'),
                AllowedFilter::exact('verificationStatus', 'verification_status'),
            ])
            ->allowedSorts([
                AllowedSort::field('documentType', 'document_type'),
                AllowedSort::field('verificationStatus', 'verification_status'),
                AllowedSort::field('createdAt', 'created_at'),
            ])
            ->defaultSort('-created_at');
    }
}
