<?php

declare(strict_types=1);

namespace Modules\Cleaning\Traits\FilterQueries;

use Modules\Cleaning\Models\ServicePricing;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\AllowedSort;
use Spatie\QueryBuilder\QueryBuilder;

trait ServicePricingFilterQuery
{
    public static function getQuery(): QueryBuilder
    {
        return QueryBuilder::for(ServicePricing::class)
            ->allowedFilters([
                AllowedFilter::exact('propertyType', 'property_type'),
                AllowedFilter::exact('cleaningServiceId', 'cleaning_service_id'),
            ])
            ->allowedSorts([
                AllowedSort::field('basePrice', 'base_price'),
                AllowedSort::field('propertyType', 'property_type'),
                AllowedSort::field('createdAt', 'created_at'),
            ])
            ->defaultSort('-created_at');
    }
}
