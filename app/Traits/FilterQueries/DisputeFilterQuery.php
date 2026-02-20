<?php

declare(strict_types=1);

namespace App\Traits\FilterQueries;

use App\Models\Dispute;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\AllowedSort;
use Spatie\QueryBuilder\QueryBuilder;

trait DisputeFilterQuery
{
    public static function getQuery(): QueryBuilder
    {
        return QueryBuilder::for(Dispute::class)
            ->allowedFilters([
                AllowedFilter::exact('status'),
                AllowedFilter::exact('category'),
                AllowedFilter::exact('bookingType', 'booking_type'),
            ])
            ->allowedSorts([
                AllowedSort::field('createdAt', 'created_at'),
                AllowedSort::field('status'),
            ])
            ->defaultSort('-created_at');
    }
}
