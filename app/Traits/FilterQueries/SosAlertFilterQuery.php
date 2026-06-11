<?php

declare(strict_types=1);

namespace App\Traits\FilterQueries;

use App\Models\SosAlert;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\AllowedSort;
use Spatie\QueryBuilder\QueryBuilder;

trait SosAlertFilterQuery
{
    public static function getQuery(): QueryBuilder
    {
        return QueryBuilder::for(SosAlert::class)
            ->allowedFilters([
                AllowedFilter::exact('status'),
                AllowedFilter::exact('emergencyType', 'emergency_type'),
                AllowedFilter::exact('source'),
            ])
            ->allowedSorts([
                AllowedSort::field('triggeredAt', 'triggered_at'),
                AllowedSort::field('createdAt', 'created_at'),
            ])
            ->defaultSort('-created_at');
    }
}
