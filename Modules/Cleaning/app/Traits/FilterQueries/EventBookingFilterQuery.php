<?php

declare(strict_types=1);

namespace Modules\Cleaning\Traits\FilterQueries;

use Illuminate\Database\Eloquent\Builder;
use Modules\Cleaning\Models\EventBooking;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\AllowedSort;
use Spatie\QueryBuilder\QueryBuilder;

trait EventBookingFilterQuery
{
    public static function getQuery(): QueryBuilder
    {
        return QueryBuilder::for(EventBooking::class)
            ->allowedFilters([
                AllowedFilter::exact('status'),
                AllowedFilter::exact('eventType', 'event_type'),
                AllowedFilter::scope('scheduledDateFrom'),
                AllowedFilter::scope('scheduledDateTo'),
            ])
            ->allowedSorts([
                AllowedSort::field('scheduledDate', 'scheduled_date'),
                AllowedSort::field('createdAt', 'created_at'),
                AllowedSort::field('status'),
                AllowedSort::field('totalPrice', 'total_price'),
            ])
            ->defaultSort('-createdAt');
    }

    public function scopeScheduledDateFrom(Builder $query, string $date): Builder
    {
        return $query->where('scheduled_date', '>=', $date);
    }

    public function scopeScheduledDateTo(Builder $query, string $date): Builder
    {
        return $query->where('scheduled_date', '<=', $date);
    }
}
