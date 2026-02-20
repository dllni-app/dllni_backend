<?php

declare(strict_types=1);

namespace App\Traits\FilterQueries;

use App\Models\SystemAlert;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\AllowedSort;
use Spatie\QueryBuilder\QueryBuilder;

trait SystemAlertFilterQuery
{
    public static function getQuery(): QueryBuilder
    {
        return QueryBuilder::for(SystemAlert::class)
            ->allowedFilters([
                AllowedFilter::exact('status'),
                AllowedFilter::exact('alertType', 'alert_type'),
                AllowedFilter::exact('severity'),
            ])
            ->allowedSorts([
                AllowedSort::field('createdAt', 'created_at'),
                AllowedSort::field('status'),
            ])
            ->defaultSort('-created_at');
    }
}
